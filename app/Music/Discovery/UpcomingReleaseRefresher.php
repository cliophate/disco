<?php

namespace App\Music\Discovery;

use App\Models\Agent;
use App\Models\CatalogEntity;
use App\Models\EntityMetadata;
use App\Models\ExternalIdentifier;
use App\Models\Release;
use App\Models\ReleaseGroup;
use App\Models\SourceObject;
use App\Models\SourceProvider;
use App\Models\SourceSnapshot;
use App\Models\UpcomingReleaseGeneration;
use App\Models\UpcomingReleaseItem;
use App\Music\CanonicalEntityResolver;
use App\Music\ListenBrainz\ListenBrainzClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class UpcomingReleaseRefresher
{
    private const ALGORITHM = 'listenbrainz-fresh-releases-v2';

    public function __construct(
        private readonly ListenBrainzClient $listenBrainz,
        private readonly CanonicalEntityResolver $canonicalEntities,
    ) {}

    /** @return array{generation_id:string,horizon_days:int,items:int,coverage:array<string,mixed>,reason:string} */
    public function refresh(?CarbonImmutable $releaseDate = null): array
    {
        $releaseDate ??= CarbonImmutable::today(config('app.timezone'));
        $lock = Cache::lock('disco:upcoming-releases', 1800);
        if (! $lock->get()) {
            throw new RuntimeException('Another upcoming-release refresh is already running.');
        }

        try {
            return $this->performRefresh($releaseDate->startOfDay());
        } finally {
            $lock->release();
        }
    }

    /** @return array{generation_id:string,horizon_days:int,items:int,coverage:array<string,mixed>,reason:string} */
    private function performRefresh(CarbonImmutable $releaseDate): array
    {
        $pastResponse = $this->listenBrainz->freshReleases($releaseDate, 30, past: true, future: false);
        $futureResponse = $this->listenBrainz->freshReleases($releaseDate, 30);
        $sourceReleases = collect([
            ...$pastResponse['payload']['releases'],
            ...$futureResponse['payload']['releases'],
        ])->unique('release_mbid')->values()->all();
        $selected = $this->eligibleGroups($sourceReleases);
        $pivot = $releaseDate->toDateString();
        $reason = 'Exact Album/EP groups released during the previous 30 days through the next 30 days.';
        $coverage = [
            'pivot_date' => $pivot,
            'window_start' => $releaseDate->subDays(30)->toDateString(),
            'window_end' => $releaseDate->addDays(30)->toDateString(),
            'past_days' => 30,
            'future_days' => 30,
            'source_past_total' => $pastResponse['payload']['total_count'],
            'source_future_total' => $futureResponse['payload']['total_count'],
            'source_total' => count($sourceReleases),
            'eligible_groups' => count($selected),
            'eligible_past' => collect($selected)->where('release_date', '<', $pivot)->count(),
            'eligible_pivot' => collect($selected)->where('release_date', $pivot)->count(),
            'eligible_future' => collect($selected)->where('release_date', '>', $pivot)->count(),
        ];
        if ($selected === []) {
            throw new RuntimeException('ListenBrainz returned no eligible albums or EPs in the release window; the previous generation was retained.');
        }

        $ordered = $this->diverseOrder($selected, $releaseDate);
        $materialized = [];
        $seenEntities = [];
        $skippedMaterializations = 0;
        foreach ($ordered as $release) {
            try {
                $entity = DB::transaction(fn (): CatalogEntity => $this->materializeReleaseGroup($release));
            } catch (RuntimeException $exception) {
                $skippedMaterializations++;
                Log::warning('Upcoming release materialization skipped a conflicting exact identity.', [
                    'release_mbid' => $release['release_mbid'],
                    'release_group_mbid' => $release['release_group_mbid'],
                    'error_code' => class_basename($exception),
                ]);

                continue;
            }
            if (isset($seenEntities[$entity->id])) {
                continue;
            }
            $seenEntities[$entity->id] = true;
            $materialized[] = ['entity_id' => $entity->id, 'release' => $release];
        }
        if ($materialized === []) {
            throw new RuntimeException('No exact release identities could be materialized; the previous generation was retained.');
        }
        $coverage['materialized_groups'] = count($materialized);
        $coverage['materialization_skipped'] = $skippedMaterializations;

        $encoded = json_encode([
            'release_date' => $pivot,
            'past_days' => 30,
            'future_days' => 30,
            'past' => $pastResponse['payload'],
            'future' => $futureResponse['payload'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $generation = DB::transaction(function () use ($coverage, $encoded, $materialized, $reason): UpcomingReleaseGeneration {
            $now = now();
            $provider = SourceProvider::query()->firstOrCreate(
                ['slug' => 'listenbrainz'],
                [
                    'display_name' => 'ListenBrainz',
                    'enabled' => true,
                    'policy' => ['storage' => 'factual_metadata', 'connector' => 'read_only', 'license' => 'CC0'],
                ],
            );
            $object = SourceObject::query()->firstOrCreate(
                ['provider_id' => $provider->id, 'object_type' => 'fresh_releases', 'external_id' => 'sitewide'],
                [
                    'canonical_url' => 'https://api.listenbrainz.org/1/explore/fresh-releases/',
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
                    'payload' => json_decode($encoded, true, 512, JSON_THROW_ON_ERROR),
                    'parser_version' => 'listenbrainz-fresh-v2',
                    'expires_at' => $now->copy()->addHours((int) config('discovery.upcoming.stale_after_hours', 36)),
                ],
            );
            $generation = UpcomingReleaseGeneration::query()->create([
                'source_snapshot_id' => $snapshot->id,
                'algorithm_version' => self::ALGORITHM,
                'horizon_days' => 30,
                'horizon_reason' => $reason,
                'coverage' => $coverage,
                'generated_at' => $now,
                'expires_at' => $now->copy()->addHours((int) config('discovery.upcoming.stale_after_hours', 36)),
            ]);

            $rank = 1;
            foreach ($materialized as ['entity_id' => $entityId, 'release' => $release]) {
                UpcomingReleaseItem::query()->create([
                    'generation_id' => $generation->id,
                    'release_group_id' => $entityId,
                    'release_group_mbid' => $release['release_group_mbid'],
                    'release_mbid' => $release['release_mbid'],
                    'title' => $release['release_name'],
                    'artist_credit_name' => $release['artist_credit_name'],
                    'artist_mbids' => $release['artist_mbids'],
                    'release_date' => $release['release_date'],
                    'primary_type' => $release['release_group_primary_type'],
                    'secondary_types' => $release['release_group_secondary_types'],
                    'artwork_status' => $release['caa_id'] !== null && $release['caa_release_mbid'] !== null ? 'available' : 'unavailable',
                    'caa_release_mbid' => $release['caa_release_mbid'],
                    'caa_id' => $release['caa_id'],
                    'listen_count' => $release['listen_count'],
                    'tags' => $release['release_tags'],
                    'general_rank' => $rank++,
                    'provenance' => [
                        'provider' => 'listenbrainz',
                        'provider_name' => 'ListenBrainz',
                        'source_url' => 'https://api.listenbrainz.org/1/explore/fresh-releases/',
                        'source_snapshot_id' => $snapshot->id,
                        'retrieved_at' => $snapshot->retrieved_at?->toAtomString(),
                        'identity_method' => 'exact_musicbrainz_ids',
                    ],
                ]);
            }

            return $generation;
        });
        UpcomingReleaseGeneration::query()->where('generated_at', '<', now()->subDays(7))->delete();

        return [
            'generation_id' => $generation->id,
            'horizon_days' => 30,
            'items' => $generation->items()->count(),
            'coverage' => $coverage,
            'reason' => $reason,
        ];
    }

    /** @param list<array<string, mixed>> $releases
     * @return list<array<string, mixed>>
     */
    private function eligibleGroups(array $releases): array
    {
        $excluded = collect(config('discovery.upcoming.excluded_secondary_types', []))->map(fn ($type): string => strtolower((string) $type))->flip();

        return collect($releases)
            ->filter(function (array $release) use ($excluded): bool {
                if (! in_array(strtolower($release['release_group_primary_type']), ['album', 'ep'], true)) {
                    return false;
                }

                return collect($release['release_group_secondary_types'])
                    ->map(fn (string $type): string => strtolower(trim($type)))
                    ->doesntContain(fn (string $type): bool => $excluded->has($type));
            })
            ->sortBy(fn (array $release): string => implode(':', [
                $release['release_date'],
                $release['caa_id'] === null ? '1' : '0',
                $release['release_mbid'],
            ]))
            ->unique('release_group_mbid')
            ->values()
            ->all();
    }

    /** @param list<array<string, mixed>> $releases
     * @return list<array<string, mixed>>
     */
    private function diverseOrder(array $releases, CarbonImmutable $pivot): array
    {
        $ordered = [];
        $grouped = collect($releases)->groupBy('release_date');
        $dates = $grouped->keys()->sort(function (string $left, string $right) use ($pivot): int {
            $pivotDate = $pivot->toDateString();
            $leftPast = $left < $pivotDate;
            $rightPast = $right < $pivotDate;
            if ($leftPast !== $rightPast) {
                return $leftPast ? 1 : -1;
            }

            return $leftPast ? strcmp($right, $left) : strcmp($left, $right);
        });
        foreach ($dates as $date) {
            $dateReleases = $grouped->get($date, collect());
            $buckets = $dateReleases
                ->sortBy(fn (array $release): array => [-$release['listen_count'], $release['release_group_mbid']])
                ->groupBy(fn (array $release): string => $release['artist_mbids'][0] ?? $release['release_group_mbid'])
                ->sortKeys()
                ->map(fn ($bucket): array => $bucket->values()->all())
                ->all();
            while ($buckets !== []) {
                foreach (array_keys($buckets) as $key) {
                    $ordered[] = array_shift($buckets[$key]);
                    if ($buckets[$key] === []) {
                        unset($buckets[$key]);
                    }
                }
            }
        }

        return $ordered;
    }

    /** @param array<string, mixed> $release */
    private function materializeReleaseGroup(array $release): CatalogEntity
    {
        $groupMbid = $release['release_group_mbid'];
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["disco:upcoming-release-group:{$groupMbid}"]);
        $identifier = ExternalIdentifier::query()
            ->where('namespace', 'musicbrainz.release_group')
            ->where('value', $groupMbid)
            ->first();
        if ($identifier !== null && $identifier->status !== 'active') {
            throw new RuntimeException("Upcoming release group [{$groupMbid}] has an inactive exact identity.");
        }
        $entity = $identifier?->entity;
        if ($entity?->status === 'redirected') {
            $entity = $this->canonicalEntities->resolve($entity->id, 'release_group');
            if ($entity !== null) {
                $identifier->update(['entity_id' => $entity->id]);
            }
        }
        if ($entity !== null && ($entity->kind !== 'release_group' || $entity->status !== 'active')) {
            throw new RuntimeException("Upcoming release group [{$groupMbid}] conflicts with the catalog.");
        }
        $entity ??= CatalogEntity::query()->create([
            'kind' => 'release_group',
            'status' => 'active',
            'canonical_name' => $release['release_name'],
            'sort_name' => $release['release_name'],
        ]);
        $groupIdentifier = ExternalIdentifier::query()->firstOrCreate(
            ['namespace' => 'musicbrainz.release_group', 'value' => $groupMbid],
            ['entity_id' => $entity->id, 'status' => 'active'],
        );
        if ($groupIdentifier->entity_id !== $entity->id || $groupIdentifier->status !== 'active') {
            throw new RuntimeException("Upcoming release group [{$groupMbid}] conflicts with its exact identifier owner.");
        }
        $date = CarbonImmutable::parse($release['release_date']);
        $group = ReleaseGroup::query()->firstOrNew(['entity_id' => $entity->id]);
        $group->fill([
            'primary_type' => $group->primary_type ?? strtolower($release['release_group_primary_type']),
            'secondary_types' => $group->secondary_types ?: $release['release_group_secondary_types'],
            'first_release_year' => $group->first_release_year ?? $date->year,
            'first_release_month' => $group->first_release_month ?? $date->month,
            'first_release_day' => $group->first_release_day ?? $date->day,
            'date_precision' => $group->exists ? $group->date_precision : 'day',
        ])->save();

        $artistEntityId = $this->singleArtistEntity($release['artist_mbids'], $release['artist_credit_name']);
        $metadata = EntityMetadata::query()->find($entity->id);
        $attributes = $metadata?->attributes ?? [];
        $attributes['upcoming_artist_mbids'] = $release['artist_mbids'];
        $attributes['basis_release_mbid'] ??= $release['release_mbid'];
        $artistCredit = collect($metadata?->artist_credit ?? [])->map(function (array $credit) use ($artistEntityId, $release): array {
            if ($artistEntityId !== null && count($release['artist_mbids']) === 1
                && strtolower((string) ($credit['artist_mbid'] ?? '')) === strtolower($release['artist_mbids'][0])) {
                $credit['artist_entity_id'] = $artistEntityId;
            }

            return $credit;
        })->all();
        $artistCredit = $artistCredit ?: [[
            'name' => $release['artist_credit_name'],
            'artist_mbid' => count($release['artist_mbids']) === 1 ? $release['artist_mbids'][0] : null,
            'artist_entity_id' => $artistEntityId,
            'joinphrase' => '',
        ]];
        EntityMetadata::query()->updateOrCreate(['entity_id' => $entity->id], [
            'source_provider' => $metadata?->source_provider ?? 'listenbrainz',
            'genres' => $metadata?->genres ?? [],
            'primary_type' => $metadata?->primary_type ?? $release['release_group_primary_type'],
            'first_release_year' => $metadata?->first_release_year ?? $date->year,
            'first_release_month' => $metadata?->first_release_month ?? $date->month,
            'first_release_day' => $metadata?->first_release_day ?? $date->day,
            'first_release_precision' => $metadata?->first_release_precision ?? 'day',
            'artist_credit' => $artistCredit,
            'external_links' => $metadata?->external_links ?? [],
            'attributes' => $attributes,
            'enriched_at' => $metadata?->enriched_at ?? now(),
        ]);
        $this->materializeRelease($release, $entity);

        return $entity;
    }

    /** @param list<string> $artistMbids */
    private function singleArtistEntity(array $artistMbids, string $creditName): ?string
    {
        if (count($artistMbids) !== 1) {
            return null;
        }
        $mbid = $artistMbids[0];
        $identifier = ExternalIdentifier::query()->where('namespace', 'musicbrainz.artist')->where('value', $mbid)->first();
        if ($identifier === null) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["disco:musicbrainz-artist:{$mbid}"]);
            $identifier = ExternalIdentifier::query()->where('namespace', 'musicbrainz.artist')->where('value', $mbid)->first();
            if ($identifier === null) {
                $artist = CatalogEntity::query()->create([
                    'kind' => 'agent',
                    'status' => 'active',
                    'canonical_name' => $creditName,
                    'sort_name' => $creditName,
                ]);
                Agent::query()->create(['entity_id' => $artist->id, 'agent_type' => 'other']);
                $identifier = ExternalIdentifier::query()->create([
                    'entity_id' => $artist->id,
                    'namespace' => 'musicbrainz.artist',
                    'value' => $mbid,
                    'status' => 'active',
                ]);
            }
        }
        if ($identifier !== null && $identifier->status !== 'active') {
            return null;
        }
        $entity = $identifier?->entity;
        if ($entity?->status === 'redirected') {
            $entity = $this->canonicalEntities->resolve($entity->id, 'agent');
        }
        if ($entity !== null && ($entity->kind !== 'agent' || $entity->status !== 'active')) {
            return null;
        }

        return $entity?->id;
    }

    /** @param array<string, mixed> $release */
    private function materializeRelease(array $release, CatalogEntity $group): void
    {
        $mbid = $release['release_mbid'];
        $identifier = ExternalIdentifier::query()->where('namespace', 'musicbrainz.release')->where('value', $mbid)->first();
        if ($identifier !== null && $identifier->status !== 'active') {
            throw new RuntimeException("Upcoming release [{$mbid}] has an inactive exact identity.");
        }
        $entity = $identifier?->entity;
        if ($entity?->status === 'redirected') {
            $entity = $this->canonicalEntities->resolve($entity->id, 'release');
        }
        if ($entity !== null && ($entity->kind !== 'release' || $entity->status !== 'active'
            || ($entity->release !== null && $entity->release->release_group_id !== $group->id))) {
            throw new RuntimeException("Upcoming release [{$mbid}] conflicts with its canonical release group.");
        }
        $entity ??= CatalogEntity::query()->create([
            'kind' => 'release', 'status' => 'active', 'canonical_name' => $release['release_name'], 'sort_name' => $release['release_name'],
        ]);
        $releaseIdentifier = ExternalIdentifier::query()->firstOrCreate(
            ['namespace' => 'musicbrainz.release', 'value' => $mbid],
            ['entity_id' => $entity->id, 'status' => 'active'],
        );
        if ($releaseIdentifier->entity_id !== $entity->id || $releaseIdentifier->status !== 'active') {
            throw new RuntimeException("Upcoming release [{$mbid}] conflicts with its exact identifier owner.");
        }
        Release::query()->firstOrCreate(['entity_id' => $entity->id], ['release_group_id' => $group->id]);
    }
}
