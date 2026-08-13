<?php

namespace App\Music\Discovery;

use App\Models\Agent;
use App\Models\CatalogEntity;
use App\Models\EntityMetadata;
use App\Models\EntityResolution;
use App\Models\ExternalIdentifier;
use App\Models\Holding;
use App\Models\PlexSyncRun;
use App\Models\RecommendationEvidence;
use App\Models\RecommendationFeedback;
use App\Models\RecommendationImpression;
use App\Models\RecommendationItem;
use App\Models\RecommendationRun;
use App\Models\Recording;
use App\Models\Release;
use App\Models\ReleaseGroup;
use App\Models\SourceObject;
use App\Models\SourceProvider;
use App\Models\SourceSnapshot;
use App\Models\User;
use App\Music\ListenBrainz\ListenBrainzClient;
use App\Music\MusicBrainz\MusicBrainzClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class ListenBrainzRecommendationRefresher
{
    private const ALGORITHM = 'listenbrainz-cf-recording-v5';

    private const RELEASE_TYPES = ['Album', 'EP', 'Single'];

    public function __construct(
        private readonly ListenBrainzClient $listenBrainz,
        private readonly MusicBrainzClient $musicBrainz,
        private readonly RecommendationDiversifier $diversifier,
    ) {}

    /** @return array{status:string,run_id:string,candidates:int,recordings:int,reused:bool} */
    public function refresh(?int $count = null, ?int $limit = null): array
    {
        $count ??= (int) config('services.listenbrainz.recommendation_count', 500);
        $limit ??= (int) config('services.listenbrainz.recommendation_limit', 50);
        if ($count < 1 || $count > 1000 || $limit < 1 || $limit > 50) {
            throw new RuntimeException('Invalid recommendation refresh limits.');
        }
        $lock = Cache::lock('disco:listenbrainz-recommendations', 3600);
        if (! $lock->get()) {
            throw new RuntimeException('Another recommendation refresh is already running.');
        }

        try {
            return $this->performRefresh($count, $limit);
        } finally {
            $lock->release();
        }
    }

    /** @return array{status:string,run_id:string,candidates:int,recordings:int,reused:bool} */
    private function performRefresh(int $count, int $limit): array
    {
        if (! $this->listenBrainz->configured()) {
            throw new RuntimeException('ListenBrainz is not configured.');
        }
        $owner = User::query()->firstOrFail();
        $response = $this->listenBrainz->recordingRecommendations($count);
        if ($response === null) {
            $this->recordNoContentSnapshot();
            $previous = $this->latestUsableRun($owner->id);

            return [
                'status' => 'no_content',
                'run_id' => $previous?->id ?? '',
                'candidates' => $previous?->items_count ?? 0,
                'recordings' => 0,
                'reused' => $previous !== null,
            ];
        }
        $payload = $response['payload'];
        $lookupBudget = min(
            $count,
            max($limit, $limit * 5),
            (int) config('services.listenbrainz.recommendation_lookup_budget', 100),
        );
        $configuration = [
            'count' => $count,
            'limit' => $limit,
            'lookup_budget' => $lookupBudget,
            'release_primary_types' => self::RELEASE_TYPES,
        ];
        $eligibilityRevision = [
            'plex' => PlexSyncRun::query()->where('status', 'completed')->max('completed_at'),
            'feedback' => (int) DB::table('discovery.feedback_revisions')->where('user_id', $owner->id)->value('revision'),
            'impressions' => RecommendationImpression::query()->where('user_id', $owner->id)->max('presented_at'),
            'resolution_epoch' => now()->startOfWeek()->format('o-W'),
        ];
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $catalogVersion = hash('sha256', $encoded.self::ALGORITHM.json_encode([$configuration, $eligibilityRevision], JSON_THROW_ON_ERROR));
        [$listenBrainzProvider, $snapshot] = $this->recordListenBrainzSnapshot($payload, $encoded);
        if ($payload['mbids'] === []) {
            $previous = $this->latestUsableRun($owner->id);

            return [
                'status' => 'no_content',
                'run_id' => $previous?->id ?? '',
                'candidates' => $previous?->items_count ?? 0,
                'recordings' => 0,
                'reused' => $previous !== null,
            ];
        }
        $existing = RecommendationRun::query()
            ->where('user_id', $owner->id)
            ->where('intent', 'beyond_library')
            ->where('catalog_version', $catalogVersion)
            ->where('status', 'completed')
            ->whereHas('items')
            ->withCount('items')
            ->first();
        if ($existing !== null) {
            $existing->update(['generated_at' => now(), 'expires_at' => now()->addDays(7)]);

            return [
                'status' => 'completed',
                'run_id' => $existing->id,
                'candidates' => $existing->items_count,
                'recordings' => count($payload['mbids']),
                'reused' => true,
            ];
        }

        $groups = [];
        $recommendations = $this->recommendationsForLookup($payload['mbids'], $lookupBudget, $catalogVersion);
        foreach ($recommendations as $recommendation) {
            try {
                $recordingPayload = $this->musicBrainz->entity('recording', $recommendation['recording_mbid']);
                if (strlen(json_encode($recordingPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) > 1024 * 1024) {
                    throw new RecommendationCandidateRejected('MusicBrainz recording payload exceeded the recommendation storage limit.');
                }
                $candidate = $this->albumCandidate($recordingPayload);
                if ($candidate === null) {
                    continue;
                }
                $groupMbid = $candidate['release_group']['id'];
                if (! isset($groups[$groupMbid]) && ! $this->eligibleExistingGroup($groupMbid, $owner->id)) {
                    continue;
                }
                $groups[$groupMbid] ??= [
                    'release_group' => $candidate['release_group'],
                    'release' => $candidate['release'],
                    'recordings' => [],
                    'raw_score' => $recommendation['score'],
                    'first_position' => count($groups),
                ];
                $groups[$groupMbid]['raw_score'] = max($groups[$groupMbid]['raw_score'], $recommendation['score']);
                if (count($groups[$groupMbid]['recordings']) < 3) {
                    $groups[$groupMbid]['recordings'][] = [
                        'recommendation' => $recommendation,
                        'payload' => $recordingPayload,
                    ];
                }
            } catch (RequestException $exception) {
                if ($exception->response->status() !== 404) {
                    throw $exception;
                }
                Log::warning('Recommendation recording could not be resolved.', [
                    'recording_mbid' => $recommendation['recording_mbid'],
                    'status' => 404,
                    'error_code' => class_basename($exception),
                ]);
            } catch (RecommendationCandidateRejected $exception) {
                Log::warning('Recommendation recording was rejected.', [
                    'recording_mbid' => $recommendation['recording_mbid'],
                    'error_code' => class_basename($exception),
                ]);
            }
        }
        uasort($groups, fn (array $left, array $right): int => [$right['raw_score'], $left['first_position']] <=> [$left['raw_score'], $right['first_position']]);
        $groups = array_slice($groups, 0, min(count($groups), $limit * 2), preserve_keys: true);

        $materialized = [];
        foreach ($groups as $candidate) {
            try {
                $resolved = DB::transaction(fn (): array => $this->materialize($candidate));
                if (Holding::query()->where('release_group_id', $resolved['entity_id'])->exists()
                    || RecommendationFeedback::query()
                        ->where('user_id', $owner->id)
                        ->where('entity_id', $resolved['entity_id'])
                        ->whereIn('action', ['not_for_me', 'already_know', 'wrong_match'])
                        ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                        ->exists()) {
                    continue;
                }
                $materialized[] = [...$candidate, ...$resolved];
                if (count($materialized) >= $limit) {
                    break;
                }
            } catch (RecommendationCandidateRejected $exception) {
                Log::warning('Recommendation album could not be materialized.', [
                    'release_group_mbid' => $candidate['release_group']['id'],
                    'error_code' => class_basename($exception),
                ]);
            }
        }
        $interested = RecommendationFeedback::query()
            ->where('user_id', $owner->id)
            ->where('action', 'interested')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('entity_id');
        $recentlyPresented = RecommendationImpression::query()
            ->where('user_id', $owner->id)
            ->where('presented_at', '>=', now()->subDays((int) config('discovery.presentation_cooldown_days', 30)))
            ->whereNotIn('entity_id', $interested)
            ->pluck('entity_id')
            ->flip();
        $materialized = array_map(fn (array $candidate): array => [
            ...$candidate,
            'recently_presented' => $recentlyPresented->has($candidate['entity_id']),
        ], $materialized);
        $materialized = $this->diversifier->external($materialized, $limit, $catalogVersion);
        $scores = array_column($materialized, 'raw_score');
        $maximum = $scores === [] ? 0.0 : max($scores);
        $minimum = $scores === [] ? 0.0 : min($scores);
        if ($materialized === [] && $payload['mbids'] !== []) {
            $previous = $this->latestUsableRun($owner->id);
            if ($previous !== null) {
                return [
                    'status' => 'retained_previous',
                    'run_id' => $previous->id,
                    'candidates' => $previous->items_count,
                    'recordings' => count($payload['mbids']),
                    'reused' => true,
                ];
            }
            throw new RuntimeException('ListenBrainz recommendations did not resolve to any eligible albums.');
        }

        $run = DB::transaction(function () use (
            $catalogVersion,
            $configuration,
            $eligibilityRevision,
            $listenBrainzProvider,
            $materialized,
            $maximum,
            $minimum,
            $owner,
            $payload,
            $snapshot,
        ): RecommendationRun {
            $run = RecommendationRun::query()->create([
                'user_id' => $owner->id,
                'intent' => 'beyond_library',
                'input' => [
                    'source_snapshot_id' => $snapshot->id,
                    'provider' => 'listenbrainz',
                    'provider_last_updated' => $payload['last_updated'],
                    'model_id' => $payload['model_id'],
                    'requested_count' => $payload['count'],
                    'eligibility_revision' => $eligibilityRevision,
                ],
                'algorithm_version' => self::ALGORITHM,
                'configuration_hash' => hash('sha256', json_encode($configuration, JSON_THROW_ON_ERROR)),
                'random_seed' => (int) sprintf('%u', crc32($catalogVersion)),
                'catalog_version' => $catalogVersion,
                'status' => 'completed',
                'generated_at' => now(),
                'expires_at' => now()->addDays(7),
            ]);
            foreach ($materialized as $position => $candidate) {
                $rawNormalized = $maximum > $minimum
                    ? ($candidate['raw_score'] - $minimum) / ($maximum - $minimum)
                    : 1.0;
                $support = min(1, count($candidate['recordings']) / 3);
                $score = round(min(1, max(0, $rawNormalized * 0.85 + $support * 0.15)), 6);
                $item = RecommendationItem::query()->create([
                    'run_id' => $run->id,
                    'entity_id' => $candidate['entity_id'],
                    'rank' => $position + 1,
                    'score' => $score,
                    'component_scores' => [
                        'listenbrainz_cf_raw' => $candidate['raw_score'],
                        'listenbrainz_cf_normalized' => round($rawNormalized, 6),
                        'recording_support' => $support,
                    ],
                    'eligibility' => [
                        'scope' => 'external',
                        'owned' => false,
                        'release_group_mbid' => $candidate['release_group']['id'],
                        'release_type' => strtolower($candidate['release_group']['primary-type']),
                        'recommendation_recordings' => count($candidate['recordings']),
                    ],
                    'module_type' => 'beyond-library',
                    'explanation_text' => 'ListenBrainz recommended '.count($candidate['recordings']).' recording'.(count($candidate['recordings']) === 1 ? '' : 's').' from this release.',
                    'explanation_version' => 'listenbrainz-cf-v1',
                ]);
                foreach ($candidate['recordings'] as $recording) {
                    $recordingId = $candidate['recording_entity_ids'][$recording['payload']['id']];
                    $recordingScore = $maximum > 0 ? min(1, max(0, $recording['recommendation']['score'] / $maximum)) : 0;
                    RecommendationEvidence::query()->create([
                        'recommendation_item_id' => $item->id,
                        'evidence_type' => 'listenbrainz_cf_recording',
                        'subject_entity_id' => $candidate['entity_id'],
                        'predicate' => 'listenbrainz.cf.recommended_recording',
                        'object_entity_id' => $recordingId,
                        'source_provider_id' => $listenBrainzProvider->id,
                        'source_slug' => 'listenbrainz',
                        'weight' => round($recordingScore, 6),
                        'display_text' => "ListenBrainz recommended {$recording['payload']['title']}; this album contains that recording.",
                    ]);
                }
            }

            return $run;
        });

        return [
            'status' => 'completed',
            'run_id' => $run->id,
            'candidates' => count($materialized),
            'recordings' => count($payload['mbids']),
            'reused' => false,
        ];
    }

    private function latestUsableRun(string $userId): ?RecommendationRun
    {
        return RecommendationRun::query()
            ->where('user_id', $userId)
            ->where('intent', 'beyond_library')
            ->where('status', 'completed')
            ->whereHas('items')
            ->latest('generated_at')
            ->withCount('items')
            ->first();
    }

    /**
     * @param  list<array{recording_mbid:string,score:float,latest_listened_at:?string}>  $recommendations
     * @return Collection<int, array{recording_mbid:string,score:float,latest_listened_at:?string}>
     */
    private function recommendationsForLookup(array $recommendations, int $limit, string $seed): Collection
    {
        return collect($recommendations)
            ->unique('recording_mbid')
            ->sortBy(fn (array $recommendation): string => hash('sha256', $seed.$recommendation['recording_mbid']))
            ->take($limit)
            ->sortByDesc('score')
            ->values();
    }

    /** @param array<string, mixed> $payload
     * @return array{release_group:array<string,mixed>,release:array<string,mixed>}|null
     */
    private function albumCandidate(array $payload): ?array
    {
        $releases = collect($payload['releases'] ?? [])
            ->filter(function ($release): bool {
                $group = is_array($release) && is_array($release['release-group'] ?? null) ? $release['release-group'] : null;
                $secondaryTypes = is_array($group['secondary-types'] ?? null) ? $group['secondary-types'] : [];

                return $group !== null
                    && is_string($release['id'] ?? null) && Str::isUuid($release['id'])
                    && is_string($group['id'] ?? null) && Str::isUuid($group['id'])
                    && in_array($group['primary-type'] ?? null, self::RELEASE_TYPES, true)
                    && ! in_array('Compilation', $secondaryTypes, true);
            })
            ->sortBy(function (array $release): string {
                $type = array_search($release['release-group']['primary-type'], self::RELEASE_TYPES, true);

                return sprintf(
                    '%d:%d:%s:%s',
                    ($release['status'] ?? null) === 'Official' ? 0 : 1,
                    $type === false ? count(self::RELEASE_TYPES) : $type,
                    $release['date'] ?? '9999',
                    $release['id'],
                );
            });
        $release = $releases->first();
        if ($release === null) {
            return null;
        }

        return ['release_group' => $release['release-group'], 'release' => $release];
    }

    private function eligibleExistingGroup(string $mbid, string $userId): bool
    {
        $entityId = ExternalIdentifier::query()
            ->where('namespace', 'musicbrainz.release_group')
            ->where('value', strtolower($mbid))
            ->where('status', 'active')
            ->value('entity_id');
        if ($entityId === null) {
            return true;
        }

        return ! Holding::query()->where('release_group_id', $entityId)->exists()
            && ! RecommendationFeedback::query()
                ->where('user_id', $userId)
                ->where('entity_id', $entityId)
                ->whereIn('action', ['not_for_me', 'already_know', 'wrong_match'])
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->exists();
    }

    /** @param array<string, mixed> $candidate
     * @return array{entity_id:string,recording_entity_ids:array<string,string>}
     */
    private function materialize(array $candidate): array
    {
        $groupPayload = $candidate['release_group'];
        $releasePayload = $candidate['release'];
        $groupMbid = strtolower($groupPayload['id']);
        $identifier = ExternalIdentifier::query()
            ->where('namespace', 'musicbrainz.release_group')
            ->where('value', $groupMbid)
            ->first();
        if ($identifier !== null && $identifier->status !== 'active') {
            throw new RecommendationCandidateRejected('MusicBrainz release-group identity is inactive.');
        }
        $groupEntity = $identifier?->entity;
        if ($groupEntity !== null && $groupEntity->status === 'redirected' && $groupEntity->redirect_entity_id !== null) {
            $groupEntity = CatalogEntity::query()->findOrFail($groupEntity->redirect_entity_id);
            $identifier->update(['entity_id' => $groupEntity->id]);
        }
        if ($groupEntity !== null && $groupEntity->kind !== 'release_group') {
            throw new RecommendationCandidateRejected('MusicBrainz release-group identity has an invalid catalog kind.');
        }
        $groupEntity ??= CatalogEntity::query()->create([
            'kind' => 'release_group',
            'status' => 'active',
            'canonical_name' => $groupPayload['title'] ?? $releasePayload['title'],
            'sort_name' => $groupPayload['title'] ?? $releasePayload['title'],
            'disambiguation' => $groupPayload['disambiguation'] ?? null,
        ]);
        ExternalIdentifier::query()->firstOrCreate(
            ['namespace' => 'musicbrainz.release_group', 'value' => $groupMbid],
            ['entity_id' => $groupEntity->id, 'status' => 'active'],
        );
        $firstRelease = $this->dateParts($groupPayload['first-release-date'] ?? $releasePayload['date'] ?? null);
        $artistCredit = $this->materializeArtistCredits($groupPayload['artist-credit'] ?? $releasePayload['artist-credit'] ?? []);
        ReleaseGroup::query()->updateOrCreate(
            ['entity_id' => $groupEntity->id],
            [
                'primary_type' => strtolower((string) ($groupPayload['primary-type'] ?? 'album')),
                'secondary_types' => $groupPayload['secondary-types'] ?? [],
                'first_release_year' => $firstRelease['year'],
                'first_release_month' => $firstRelease['month'],
                'first_release_day' => $firstRelease['day'],
                'date_precision' => $firstRelease['precision'],
            ],
        );
        $existingMetadata = EntityMetadata::query()->find($groupEntity->id);
        EntityMetadata::query()->updateOrCreate(
            ['entity_id' => $groupEntity->id],
            [
                'source_provider' => 'musicbrainz',
                'genres' => $existingMetadata?->genres ?? [],
                'primary_type' => $groupPayload['primary-type'] ?? 'Album',
                'first_release_year' => $firstRelease['year'],
                'first_release_month' => $firstRelease['month'],
                'first_release_day' => $firstRelease['day'],
                'first_release_precision' => $firstRelease['precision'] === 'unknown' ? null : $firstRelease['precision'],
                'disambiguation' => $groupPayload['disambiguation'] ?? null,
                'artist_credit' => $artistCredit,
                'external_links' => $existingMetadata?->external_links ?? [],
                'attributes' => [
                    ...($existingMetadata?->attributes ?? []),
                    'secondary_types' => $groupPayload['secondary-types'] ?? [],
                    'basis_release_mbid' => strtolower($releasePayload['id']),
                    'release_group_mbid' => $groupMbid,
                ],
                'enriched_at' => now(),
            ],
        );

        $this->materializeRelease($releasePayload, $groupEntity);
        $recordingEntityIds = [];
        foreach ($candidate['recordings'] as $recording) {
            $recordingEntity = $this->materializeRecording($recording['payload']);
            $recordingEntityIds[$recording['payload']['id']] = $recordingEntity->id;
        }

        return ['entity_id' => $groupEntity->id, 'recording_entity_ids' => $recordingEntityIds];
    }

    /** @param array<string, mixed> $payload */
    private function materializeRelease(array $payload, CatalogEntity $groupEntity): void
    {
        $mbid = strtolower($payload['id']);
        $identifier = ExternalIdentifier::query()
            ->where('namespace', 'musicbrainz.release')
            ->where('value', $mbid)
            ->first();
        if ($identifier !== null && $identifier->status !== 'active') {
            throw new RecommendationCandidateRejected('MusicBrainz release identity is inactive.');
        }
        $releaseEntity = $identifier?->entity;
        if ($releaseEntity !== null && ($releaseEntity->kind !== 'release'
            || $releaseEntity->release?->release_group_id !== $groupEntity->id)) {
            throw new RecommendationCandidateRejected('MusicBrainz release conflicts with the catalog hierarchy.');
        }
        $releaseEntity ??= CatalogEntity::query()->create([
            'kind' => 'release',
            'status' => 'active',
            'canonical_name' => $payload['title'],
            'sort_name' => $payload['title'],
            'disambiguation' => $payload['disambiguation'] ?? null,
        ]);
        ExternalIdentifier::query()->firstOrCreate(
            ['namespace' => 'musicbrainz.release', 'value' => $mbid],
            ['entity_id' => $releaseEntity->id, 'status' => 'active'],
        );
        $date = $this->dateParts($payload['date'] ?? null);
        Release::query()->updateOrCreate(
            ['entity_id' => $releaseEntity->id],
            [
                'release_group_id' => $groupEntity->id,
                'status' => strtolower((string) ($payload['status'] ?? 'unknown')),
                'country_code' => $payload['country'] ?? null,
                'barcode' => $payload['barcode'] ?? null,
                'release_year' => $date['year'],
                'release_month' => $date['month'],
                'release_day' => $date['day'],
                'date_precision' => $date['precision'],
                'edition_summary' => $payload['disambiguation'] ?? null,
            ],
        );
    }

    /** @param array<string, mixed> $payload */
    private function materializeRecording(array $payload): CatalogEntity
    {
        $mbid = strtolower($payload['id']);
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["disco:musicbrainz-recording:{$mbid}"]);
        $identifier = ExternalIdentifier::query()
            ->where('namespace', 'musicbrainz.recording')
            ->where('value', $mbid)
            ->first();
        if ($identifier !== null && $identifier->status !== 'active') {
            throw new RecommendationCandidateRejected('MusicBrainz recording identity is inactive.');
        }
        $entity = $identifier?->entity;
        if ($entity !== null && $entity->kind !== 'recording') {
            throw new RecommendationCandidateRejected('MusicBrainz recording identity has an invalid catalog kind.');
        }
        $entity ??= CatalogEntity::query()->create([
            'kind' => 'recording',
            'status' => 'active',
            'canonical_name' => $payload['title'],
            'sort_name' => $payload['title'],
            'disambiguation' => $payload['disambiguation'] ?? null,
        ]);
        $identifier = ExternalIdentifier::query()->firstOrCreate(
            ['namespace' => 'musicbrainz.recording', 'value' => $mbid],
            ['entity_id' => $entity->id, 'status' => 'active'],
        );
        $entity = $identifier->entity;
        if ($entity === null || $entity->kind !== 'recording') {
            throw new RecommendationCandidateRejected('MusicBrainz recording identity could not be established.');
        }
        $existingRecording = Recording::query()->find($entity->id);
        Recording::query()->updateOrCreate(
            ['entity_id' => $entity->id],
            ['duration_ms' => is_int($payload['length'] ?? null) ? $payload['length'] : $existingRecording?->duration_ms],
        );
        $existingMetadata = EntityMetadata::query()->find($entity->id);
        EntityMetadata::query()->updateOrCreate(
            ['entity_id' => $entity->id],
            [
                'source_provider' => 'musicbrainz',
                'artist_credit' => $this->materializeArtistCredits($payload['artist-credit'] ?? []),
                'external_links' => $existingMetadata?->external_links ?? [],
                'attributes' => $existingMetadata?->attributes ?? [],
                'enriched_at' => now(),
            ],
        );
        $provider = SourceProvider::query()->firstOrCreate(
            ['slug' => 'musicbrainz'],
            ['display_name' => 'MusicBrainz', 'enabled' => true, 'policy' => ['storage' => 'metadata', 'connector' => 'read_only', 'license' => 'CC0']],
        );
        $now = now();
        $object = SourceObject::query()->firstOrCreate(
            ['provider_id' => $provider->id, 'object_type' => 'recording', 'external_id' => $mbid],
            [
                'canonical_url' => "https://musicbrainz.org/recording/{$mbid}",
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ],
        );
        $object->update([
            'canonical_url' => "https://musicbrainz.org/recording/{$mbid}",
            'last_seen_at' => $now,
        ]);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $snapshot = SourceSnapshot::query()->firstOrCreate(
            ['source_object_id' => $object->id, 'payload_hash' => hash('sha256', $encoded)],
            [
                'retrieved_at' => $now,
                'http_status' => 200,
                'payload' => $payload,
                'parser_version' => 'musicbrainz-json-v1',
                'expires_at' => $now->copy()->addDays(90),
            ],
        );
        EntityResolution::query()
            ->where('source_object_id', $object->id)
            ->where('resolution_scope', 'recording')
            ->whereIn('status', ['confirmed', 'candidate'])
            ->where('entity_id', '!=', $entity->id)
            ->update(['status' => 'superseded']);
        EntityResolution::query()->updateOrCreate(
            ['source_object_id' => $object->id, 'entity_id' => $entity->id, 'resolution_scope' => 'recording'],
            [
                'status' => 'confirmed',
                'method' => 'external_id',
                'confidence' => 1,
                'algorithm_version' => self::ALGORITHM,
                'evidence' => ['snapshot_payload_hash' => $snapshot->payload_hash],
            ],
        );

        return $entity;
    }

    /** @param array<string, mixed> $payload
     * @return array{0:SourceProvider,1:SourceSnapshot}
     */
    private function recordListenBrainzSnapshot(array $payload, string $encoded): array
    {
        $provider = SourceProvider::query()->firstOrCreate(
            ['slug' => 'listenbrainz'],
            [
                'display_name' => 'ListenBrainz',
                'enabled' => true,
                'policy' => ['storage' => 'recommendations', 'connector' => 'read_only', 'license' => 'CC0'],
            ],
        );
        $now = now();
        $username = $payload['user_name'];
        $object = SourceObject::query()->firstOrCreate(
            ['provider_id' => $provider->id, 'object_type' => 'cf_recording_recommendations', 'external_id' => $username],
            [
                'canonical_url' => 'https://listenbrainz.org/user/'.rawurlencode($username).'/recommendations',
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ],
        );
        $object->update(['last_seen_at' => $now]);
        $snapshot = SourceSnapshot::query()->firstOrCreate(
            ['source_object_id' => $object->id, 'payload_hash' => hash('sha256', $encoded)],
            [
                'retrieved_at' => $now,
                'http_status' => 200,
                'payload' => $payload,
                'parser_version' => 'listenbrainz-cf-v1',
                'expires_at' => $now->copy()->addDays(7),
            ],
        );

        return [$provider, $snapshot];
    }

    private function recordNoContentSnapshot(): void
    {
        $provider = SourceProvider::query()->firstOrCreate(
            ['slug' => 'listenbrainz'],
            [
                'display_name' => 'ListenBrainz',
                'enabled' => true,
                'policy' => ['storage' => 'recommendations', 'connector' => 'read_only', 'license' => 'CC0'],
            ],
        );
        $now = now();
        $username = (string) config('services.listenbrainz.username');
        $object = SourceObject::query()->firstOrCreate(
            ['provider_id' => $provider->id, 'object_type' => 'cf_recording_recommendations', 'external_id' => $username],
            [
                'canonical_url' => 'https://listenbrainz.org/user/'.rawurlencode($username).'/recommendations',
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ],
        );
        $object->update(['last_seen_at' => $now]);
        SourceSnapshot::query()->firstOrCreate(
            ['source_object_id' => $object->id, 'payload_hash' => hash('sha256', '204-no-content')],
            [
                'retrieved_at' => $now,
                'http_status' => 204,
                'payload' => null,
                'parser_version' => 'listenbrainz-cf-v1',
                'expires_at' => $now->copy()->addDays(7),
            ],
        );
    }

    /** @return list<array{name:string,artist_mbid:?string,artist_entity_id:?string,joinphrase:string}> */
    private function materializeArtistCredits(mixed $credits): array
    {
        $credits = is_array($credits) ? $credits : [];
        if (count($credits) > 20) {
            throw new RecommendationCandidateRejected('MusicBrainz artist credit exceeds the supported size.');
        }

        return collect($credits)->map(function (array $credit): ?array {
            $name = $credit['name'] ?? data_get($credit, 'artist.name');
            if (! is_string($name) || trim($name) === '') {
                return null;
            }

            $mbid = data_get($credit, 'artist.id');
            $mbid = is_string($mbid) && Str::isUuid($mbid) ? strtolower($mbid) : null;
            $canonicalName = data_get($credit, 'artist.name');
            $entity = $mbid === null
                ? null
                : $this->materializeArtist($mbid, is_string($canonicalName) && trim($canonicalName) !== '' ? $canonicalName : $name);

            return [
                'name' => $name,
                'artist_mbid' => $mbid,
                'artist_entity_id' => $entity?->id,
                'joinphrase' => is_string($credit['joinphrase'] ?? null) ? $credit['joinphrase'] : '',
            ];
        })->filter()->values()->all();
    }

    private function materializeArtist(string $mbid, string $name): CatalogEntity
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["disco:musicbrainz-artist:{$mbid}"]);
        $identifier = ExternalIdentifier::query()
            ->where('namespace', 'musicbrainz.artist')
            ->where('value', $mbid)
            ->first();
        if ($identifier !== null && ! in_array($identifier->status, ['active', 'redirected'], true)) {
            throw new RecommendationCandidateRejected('MusicBrainz artist identity is inactive.');
        }

        $entity = $identifier?->entity;
        $resolvedRedirect = false;
        if ($entity !== null && $entity->status === 'redirected' && $entity->redirect_entity_id !== null) {
            $entity = CatalogEntity::query()->findOrFail($entity->redirect_entity_id);
            $identifier->update(['status' => 'redirected']);
            $resolvedRedirect = true;
        }
        if ($entity !== null && ($entity->kind !== 'agent' || $entity->status !== 'active')) {
            throw new RecommendationCandidateRejected('MusicBrainz artist identity has an invalid catalog target.');
        }

        $createdEntity = null;
        if ($entity === null) {
            $createdEntity = $entity = CatalogEntity::query()->create([
                'kind' => 'agent',
                'status' => 'active',
                'canonical_name' => $name,
                'sort_name' => $name,
            ]);
        }
        if (! $resolvedRedirect) {
            $identifier = ExternalIdentifier::query()->firstOrCreate(
                ['namespace' => 'musicbrainz.artist', 'value' => $mbid],
                ['entity_id' => $entity->id, 'status' => 'active'],
            );
            if ($createdEntity !== null && $identifier->entity_id !== $createdEntity->id) {
                $createdEntity->delete();
            }
            $entity = $identifier->entity;
        }
        if ($entity === null || $entity->kind !== 'agent' || $entity->status !== 'active') {
            throw new RecommendationCandidateRejected('MusicBrainz artist identity could not be established.');
        }
        Agent::query()->firstOrCreate(['entity_id' => $entity->id], ['agent_type' => 'other']);

        return $entity;
    }

    /** @return array{year:?int,month:?int,day:?int,precision:string} */
    private function dateParts(mixed $value): array
    {
        $parts = is_string($value) && preg_match('/\A([0-9]{1,4})(?:-([0-9]{2})(?:-([0-9]{2}))?)?\z/', $value, $matches) === 1
            ? $matches
            : [];

        return [
            'year' => isset($parts[1]) ? (int) $parts[1] : null,
            'month' => isset($parts[2]) ? (int) $parts[2] : null,
            'day' => isset($parts[3]) ? (int) $parts[3] : null,
            'precision' => isset($parts[3]) ? 'day' : (isset($parts[2]) ? 'month' : (isset($parts[1]) ? 'year' : 'unknown')),
        ];
    }
}
