<?php

namespace App\Music\Discogs;

use App\Models\CatalogEntity;
use App\Models\DiscogsEnrichmentState;
use App\Models\EntityResolution;
use App\Models\ExternalIdentifier;
use App\Models\SourceAssertion;
use App\Models\SourceObject;
use App\Models\SourceProvider;
use App\Models\SourceSnapshot;
use App\Music\MusicBrainz\MusicBrainzClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class DiscogsEnricher
{
    private const RESTRICTED_KEYS = [
        'community', 'estimated_weight', 'images', 'lowest_price', 'marketplace', 'num_for_sale',
        'profile', 'resource_url', 'thumb', 'videos', 'wantlist',
    ];

    public function __construct(
        private readonly DiscogsClient $discogs,
        private readonly MusicBrainzClient $musicBrainz,
    ) {}

    public function configured(): bool
    {
        return $this->discogs->configured();
    }

    /** @return array<string, int|bool> */
    public function enrichOwned(int $limit = 20, bool $force = false, bool $dryRun = false): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new RuntimeException('Discogs enrichment limit must be between 1 and 100.');
        }
        $counts = [
            'enabled' => $this->discogs->configured(),
            'requested' => 0,
            'matched' => 0,
            'refreshed' => 0,
            'missing' => 0,
            'ambiguous' => 0,
            'conflicts' => 0,
            'failed' => 0,
            'musicbrainz_requests' => 0,
            'discogs_requests' => 0,
            'restricted_fields_dropped' => 0,
        ];
        if (! $counts['enabled']) {
            return $counts;
        }

        $entities = $this->eligibleQuery()
            ->leftJoin('source.discogs_enrichment_states as discogs_state', 'discogs_state.entity_id', '=', 'entities.id')
            ->when(! $force, fn (Builder $query) => $query->where(fn (Builder $due) => $due
                ->whereNull('discogs_state.entity_id')
                ->orWhereNull('discogs_state.retry_at')
                ->orWhere('discogs_state.retry_at', '<=', now())))
            ->select('entities.*')
            ->orderByRaw('discogs_state.retry_at ASC NULLS FIRST')
            ->orderBy('entities.id')
            ->limit($limit)
            ->get();

        foreach ($entities as $entity) {
            $counts['requested']++;
            try {
                $result = $this->enrichEntity($entity, $dryRun);
                $counts[$result['status']]++;
                $counts['musicbrainz_requests'] += $result['musicbrainz_requests'];
                $counts['discogs_requests'] += $result['discogs_requests'];
                $counts['restricted_fields_dropped'] += $result['restricted_fields_dropped'];
            } catch (Throwable $exception) {
                if (! $dryRun) {
                    $this->recordState($entity, 'failed', now()->addHours(6), class_basename($exception));
                }
                Log::warning('Discogs enrichment failed.', [
                    'entity_id' => $entity->id,
                    'entity_kind' => $entity->kind,
                    'error_code' => class_basename($exception),
                ]);
                $counts['failed']++;
            }
        }

        return $counts;
    }

    /** @return array{eligible:int,identified:int,fresh:int,stale:int,restricted_snapshots:int} */
    public function coverage(): array
    {
        $eligible = $this->eligibleQuery()->count();
        $identifiedIds = DB::table('source.entity_resolutions as resolutions')
            ->join('source.objects as objects', 'objects.id', '=', 'resolutions.source_object_id')
            ->join('source.providers as providers', 'providers.id', '=', 'objects.provider_id')
            ->where('providers.slug', 'discogs')
            ->where('resolutions.status', 'confirmed')
            ->distinct()
            ->pluck('resolutions.entity_id');
        $fresh = DB::table('source.entity_resolutions as resolutions')
            ->join('source.objects as objects', 'objects.id', '=', 'resolutions.source_object_id')
            ->join('source.providers as providers', 'providers.id', '=', 'objects.provider_id')
            ->join('source.snapshots as snapshots', 'snapshots.source_object_id', '=', 'objects.id')
            ->where('providers.slug', 'discogs')
            ->where('resolutions.status', 'confirmed')
            ->where('snapshots.http_status', 200)
            ->where('snapshots.expires_at', '>', now())
            ->distinct()
            ->count('resolutions.entity_id');
        $restricted = SourceSnapshot::query()
            ->whereHas('object.provider', fn (Builder $query) => $query->where('slug', 'discogs'))
            ->get(['payload'])
            ->filter(fn (SourceSnapshot $snapshot): bool => array_intersect(array_keys($snapshot->payload ?? []), self::RESTRICTED_KEYS) !== [])
            ->count();

        return [
            'eligible' => $eligible,
            'identified' => $identifiedIds->count(),
            'fresh' => $fresh,
            'stale' => max(0, $identifiedIds->count() - $fresh),
            'restricted_snapshots' => $restricted,
        ];
    }

    /** @return Collection<int, string> */
    public function eligibleEntityIds(): Collection
    {
        return $this->eligibleQuery()->pluck('entities.id');
    }

    /** @return array{status:string,musicbrainz_requests:int,discogs_requests:int,restricted_fields_dropped:int} */
    public function retryEntity(CatalogEntity $entity): array
    {
        if (! $this->discogs->configured() || ! $this->eligibleQuery()->whereKey($entity->id)->exists()) {
            throw new RuntimeException('Discogs retry requires a configured provider and an eligible exact entity.');
        }

        return Cache::lock("disco:discogs-entity:{$entity->id}", 180)->block(5, function () use ($entity): array {
            try {
                return $this->enrichEntity($entity, false);
            } catch (Throwable $exception) {
                $this->recordState($entity, 'failed', now()->addHours(6), class_basename($exception));
                Log::warning('Discogs enrichment retry failed.', ['entity_id' => $entity->id, 'error_code' => class_basename($exception)]);

                throw $exception;
            }
        });
    }

    /** @return array{status:string,musicbrainz_requests:int,discogs_requests:int,restricted_fields_dropped:int} */
    private function enrichEntity(CatalogEntity $entity, bool $dryRun): array
    {
        $musicBrainzRequests = 0;
        $object = $this->resolvedObject($entity);
        $newIdentity = $object === null;
        if ($object === null) {
            $identifier = $this->exactMusicBrainzIdentifier($entity);
            $payload = $this->musicBrainz->entity($entity->kind === 'agent' ? 'artist' : 'release-group', $identifier->value);
            $musicBrainzRequests++;
            $references = $this->discogsReferences($entity, $payload);
            if ($references === []) {
                if (! $dryRun) {
                    $this->recordState($entity, 'missing', now()->addDays(7), evidence: ['musicbrainz_mbid' => $identifier->value]);
                }

                return $this->result('missing', $musicBrainzRequests);
            }
            if (count($references) !== 1) {
                if (! $dryRun) {
                    $this->recordState($entity, 'ambiguous', now()->addDays(7), evidence: [
                        'musicbrainz_mbid' => $identifier->value,
                        'candidate_urls' => array_column($references, 'url'),
                    ]);
                }

                return $this->result('ambiguous', $musicBrainzRequests);
            }
            $reference = $references[0];
            if ($this->identityConflicts($entity, $reference)) {
                if (! $dryRun) {
                    $this->recordState($entity, 'conflict', now()->addDays(30), evidence: ['candidate_url' => $reference['url']]);
                }

                return $this->result('conflicts', $musicBrainzRequests);
            }
            $object = $dryRun ? new SourceObject([
                'object_type' => $reference['type'],
                'external_id' => $reference['id'],
                'canonical_url' => $reference['url'],
            ]) : $this->persistIdentity($entity, $identifier, $reference);
        }

        $raw = $this->discogs->catalogObject($object->object_type, $object->external_id);
        $sanitized = $this->sanitize($object->object_type, $raw);
        $dropped = count(array_intersect(array_keys($raw), self::RESTRICTED_KEYS));
        if (! $dryRun) {
            $this->persistSnapshot($entity, $object, $sanitized);
            $this->recordState($entity, 'ready', now()->addHours(5), evidence: [
                'source_url' => $object->canonical_url,
                'field_count' => count($sanitized),
            ]);
        }

        return [
            'status' => $newIdentity ? 'matched' : 'refreshed',
            'musicbrainz_requests' => $musicBrainzRequests,
            'discogs_requests' => 1,
            'restricted_fields_dropped' => $dropped,
        ];
    }

    private function eligibleQuery(): Builder
    {
        return CatalogEntity::query()
            ->where('entities.status', 'active')
            ->where(function (Builder $query): void {
                $query->where(fn (Builder $artist) => $artist
                    ->where('entities.kind', 'agent')
                    ->whereHas('identifiers', fn (Builder $identifiers) => $identifiers
                        ->where('namespace', 'musicbrainz.artist')->where('status', 'active')))
                    ->orWhere(fn (Builder $album) => $album
                        ->where('entities.kind', 'release_group')
                        ->whereHas('identifiers', fn (Builder $identifiers) => $identifiers
                            ->where('namespace', 'musicbrainz.release_group')->where('status', 'active')));
            })
            ->whereHas('plexMatches', fn (Builder $matches) => $matches
                ->where('status', 'confirmed')
                ->whereHas('item', fn (Builder $items) => $items->whereNull('removed_at')));
    }

    private function exactMusicBrainzIdentifier(CatalogEntity $entity): ExternalIdentifier
    {
        $namespace = $entity->kind === 'agent' ? 'musicbrainz.artist' : 'musicbrainz.release_group';
        $identifiers = $entity->identifiers()->where('namespace', $namespace)->where('status', 'active')->get();
        if ($identifiers->count() !== 1) {
            throw new RuntimeException('Discogs enrichment requires exactly one active MusicBrainz identity.');
        }

        return $identifiers->sole();
    }

    private function resolvedObject(CatalogEntity $entity): ?SourceObject
    {
        return SourceObject::query()
            ->whereHas('provider', fn (Builder $query) => $query->where('slug', 'discogs')->where('enabled', true))
            ->whereHas('resolutions', fn (Builder $query) => $query
                ->where('entity_id', $entity->id)->where('status', 'confirmed'))
            ->orderByDesc('last_seen_at')
            ->first();
    }

    /** @param array<string, mixed> $payload
     * @return list<array{type:string,id:string,url:string}>
     */
    private function discogsReferences(CatalogEntity $entity, array $payload): array
    {
        $references = [];
        foreach ($payload['relations'] ?? [] as $relation) {
            $url = is_array($relation) ? data_get($relation, 'url.resource') : null;
            if (! is_string($url)) {
                continue;
            }
            $parts = parse_url($url);
            $host = strtolower((string) ($parts['host'] ?? ''));
            if (($parts['scheme'] ?? null) !== 'https' || ! in_array($host, ['discogs.com', 'www.discogs.com'], true)
                || preg_match('~\A/(artist|master|release)/([1-9][0-9]{0,18})(?:-[^/?#]*)?/?\z~D', (string) ($parts['path'] ?? ''), $matches) !== 1) {
                continue;
            }
            $type = $matches[1];
            if (($entity->kind === 'agent' && $type !== 'artist')
                || ($entity->kind === 'release_group' && ! in_array($type, ['master', 'release'], true))) {
                continue;
            }
            $key = "{$type}:{$matches[2]}";
            $references[$key] = [
                'type' => $type,
                'id' => $matches[2],
                'url' => "https://www.discogs.com/{$type}/{$matches[2]}",
            ];
        }

        return array_values($references);
    }

    /** @param array{type:string,id:string,url:string} $reference */
    private function identityConflicts(CatalogEntity $entity, array $reference): bool
    {
        return ExternalIdentifier::query()
            ->where('namespace', "discogs.{$reference['type']}")
            ->where('value', $reference['id'])
            ->where('entity_id', '!=', $entity->id)
            ->where('status', 'active')
            ->exists();
    }

    /** @param array{type:string,id:string,url:string} $reference */
    private function persistIdentity(CatalogEntity $entity, ExternalIdentifier $musicBrainzIdentifier, array $reference): SourceObject
    {
        return DB::transaction(function () use ($entity, $musicBrainzIdentifier, $reference): SourceObject {
            $provider = SourceProvider::query()->firstOrCreate(
                ['slug' => 'discogs'],
                [
                    'display_name' => 'Discogs',
                    'enabled' => true,
                    'policy' => [
                        'connector' => 'read_only',
                        'license' => 'CC0 catalog fields only',
                        'freshness_hours' => 6,
                        'excluded' => ['images', 'marketplace', 'user data', 'collection', 'wantlist', 'profile'],
                    ],
                ],
            );
            $now = now();
            $object = SourceObject::query()->firstOrCreate(
                ['provider_id' => $provider->id, 'object_type' => $reference['type'], 'external_id' => $reference['id']],
                ['canonical_url' => $reference['url'], 'first_seen_at' => $now, 'last_seen_at' => $now],
            );
            $object->update(['canonical_url' => $reference['url'], 'last_seen_at' => $now]);
            $resolutionIds = EntityResolution::query()
                ->where('entity_id', $entity->id)
                ->where('resolution_scope', $entity->kind)
                ->whereIn('status', ['confirmed', 'candidate'])
                ->whereHas('object.provider', fn (Builder $query) => $query->where('slug', 'discogs'))
                ->where('source_object_id', '!=', $object->id)
                ->pluck('id');
            EntityResolution::query()->whereIn('id', $resolutionIds)->update(['status' => 'superseded']);
            EntityResolution::query()->updateOrCreate(
                ['source_object_id' => $object->id, 'entity_id' => $entity->id, 'resolution_scope' => $entity->kind],
                [
                    'status' => 'confirmed',
                    'method' => 'musicbrainz_url',
                    'confidence' => 1,
                    'algorithm_version' => 'discogs-mb-url-v1',
                    'evidence' => [
                        'musicbrainz_namespace' => $musicBrainzIdentifier->namespace,
                        'musicbrainz_mbid' => $musicBrainzIdentifier->value,
                        'relation_url' => $reference['url'],
                    ],
                ],
            );
            ExternalIdentifier::query()->updateOrCreate(
                ['namespace' => "discogs.{$reference['type']}", 'value' => $reference['id']],
                ['entity_id' => $entity->id, 'status' => 'active'],
            );

            return $object->refresh();
        });
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sanitize(string $type, array $payload): array
    {
        $base = [
            'id' => (string) $payload['id'],
            'object_type' => $type,
            'name' => $this->text($payload[$type === 'artist' ? 'name' : 'title'] ?? null, 300),
        ];
        if ($type === 'artist') {
            return array_filter($base + [
                'real_name' => $this->text($payload['realname'] ?? null, 300),
                'name_variations' => $this->strings($payload['namevariations'] ?? [], 30, 300),
            ], fn (mixed $value): bool => $value !== null && $value !== []);
        }

        return array_filter($base + [
            'year' => is_numeric($payload['year'] ?? null) && (int) $payload['year'] >= 1000 && (int) $payload['year'] <= 3000 ? (int) $payload['year'] : null,
            'released' => $this->text($payload['released'] ?? null, 40),
            'country' => $this->text($payload['country'] ?? null, 120),
            'genres' => $this->strings($payload['genres'] ?? [], 20, 80),
            'styles' => $this->strings($payload['styles'] ?? [], 30, 80),
            'formats' => collect(is_array($payload['formats'] ?? null) ? $payload['formats'] : [])->take(20)->map(fn (mixed $format): ?array => is_array($format) ? array_filter([
                'name' => $this->text($format['name'] ?? null, 120),
                'quantity' => $this->text($format['qty'] ?? null, 20),
                'descriptions' => $this->strings($format['descriptions'] ?? [], 20, 120),
            ], fn (mixed $value): bool => $value !== null && $value !== []) : null)->filter()->values()->all(),
            'labels' => collect(is_array($payload['labels'] ?? null) ? $payload['labels'] : [])->take(30)->map(fn (mixed $label): ?array => is_array($label) ? array_filter([
                'id' => is_numeric($label['id'] ?? null) ? (string) $label['id'] : null,
                'name' => $this->text($label['name'] ?? null, 200),
                'catalog_number' => $this->text($label['catno'] ?? null, 120),
            ], fn (mixed $value): bool => $value !== null) : null)->filter()->values()->all(),
            'identifiers' => collect(is_array($payload['identifiers'] ?? null) ? $payload['identifiers'] : [])->take(40)->map(fn (mixed $identifier): ?array => is_array($identifier) ? array_filter([
                'type' => $this->text($identifier['type'] ?? null, 80),
                'value' => $this->text($identifier['value'] ?? null, 300),
                'description' => $this->text($identifier['description'] ?? null, 200),
            ], fn (mixed $value): bool => $value !== null) : null)->filter()->values()->all(),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /** @param array<string, mixed> $payload */
    private function persistSnapshot(CatalogEntity $entity, SourceObject $object, array $payload): void
    {
        DB::transaction(function () use ($entity, $object, $payload): void {
            $now = now();
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $snapshot = SourceSnapshot::query()->updateOrCreate(
                ['source_object_id' => $object->id, 'payload_hash' => hash('sha256', $encoded)],
                [
                    'retrieved_at' => $now,
                    'http_status' => 200,
                    'payload' => $payload,
                    'parser_version' => 'discogs-cc0-v1',
                    'expires_at' => $now->copy()->addHours(6),
                ],
            );
            foreach (array_diff(array_keys($payload), ['id', 'object_type']) as $field) {
                SourceAssertion::query()->updateOrCreate(
                    ['snapshot_id' => $snapshot->id, 'subject_entity_id' => $entity->id, 'predicate' => "discogs.catalog.{$field}"],
                    ['value' => ['observed' => $payload[$field]], 'status' => 'observed', 'confidence' => 1],
                );
            }
            $object->update(['last_seen_at' => $now]);
        });
    }

    /** @param array<string, mixed> $evidence */
    private function recordState(CatalogEntity $entity, string $status, mixed $retryAt, ?string $errorCode = null, array $evidence = []): void
    {
        DiscogsEnrichmentState::query()->updateOrCreate(
            ['entity_id' => $entity->id],
            [
                'status' => $status,
                'attempted_at' => now(),
                'retry_at' => $retryAt,
                'error_code' => $errorCode,
                'evidence' => $evidence,
            ],
        );
    }

    /** @return array{status:string,musicbrainz_requests:int,discogs_requests:int,restricted_fields_dropped:int} */
    private function result(string $status, int $musicBrainzRequests): array
    {
        return [
            'status' => $status,
            'musicbrainz_requests' => $musicBrainzRequests,
            'discogs_requests' => 0,
            'restricted_fields_dropped' => 0,
        ];
    }

    private function text(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    /** @return list<string> */
    private function strings(mixed $values, int $count, int $length): array
    {
        return collect(is_array($values) ? $values : [])
            ->take($count)
            ->map(fn (mixed $value): ?string => $this->text($value, $length))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
