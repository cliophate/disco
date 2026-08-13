<?php

namespace App\Music\Plex;

use App\Jobs\IngestPlexArtwork;
use App\Models\Agent;
use App\Models\CatalogEntity;
use App\Models\EntityResolution;
use App\Models\ExternalIdentifier;
use App\Models\Holding;
use App\Models\PlexEntityMatch;
use App\Models\PlexItem;
use App\Models\PlexItemGuid;
use App\Models\PlexLibrary;
use App\Models\PlexMediaPart;
use App\Models\PlexServer;
use App\Models\PlexSyncRun;
use App\Models\Recording;
use App\Models\Release;
use App\Models\ReleaseGroup;
use App\Models\SourceAssertion;
use App\Models\SourceObject;
use App\Models\SourceProvider;
use App\Models\SourceSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PlexSyncService
{
    public function __construct(private readonly PlexClient $client) {}

    /** @return array<string, int|string> */
    public function sync(bool $dryRun = false, bool $allowEmptyTypes = false): array
    {
        $lock = Cache::lock('disco:plex-sync', 3600);
        if (! $lock->get()) {
            throw new RuntimeException('Another Plex synchronization is already running.');
        }

        try {
            return $this->performSync($dryRun, $allowEmptyTypes);
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, int|string> */
    private function performSync(bool $dryRun, bool $allowEmptyTypes): array
    {
        $startedAt = now();
        $identity = $this->client->identity();
        $expected = (string) config('services.plex.expected_machine_identifier');

        if ($expected === '') {
            throw new RuntimeException('PLEX_EXPECTED_MACHINE_IDENTIFIER is required before accessing the library.');
        }

        if ($expected !== '' && ! hash_equals($expected, $identity['machine_identifier'])) {
            throw new RuntimeException('Plex machine identifier does not match the configured server.');
        }

        $section = $this->client->musicLibrary((string) config('services.plex.library', 'Music'));
        $expectedLibraryUuid = (string) config('services.plex.expected_library_uuid');
        if ($expectedLibraryUuid === '' || ! hash_equals($expectedLibraryUuid, (string) $section['uuid'])) {
            throw new RuntimeException('Plex library UUID does not match PLEX_EXPECTED_LIBRARY_UUID.');
        }
        $payload = [
            'artist' => $this->client->libraryItems($section['key'], 8),
            'album' => $this->client->libraryItems($section['key'], 9),
            'track' => $this->client->libraryItems($section['key'], 10),
        ];

        $counts = [
            'machine_identifier' => $identity['machine_identifier'],
            'library' => $section['title'],
            'artists' => count($payload['artist']),
            'albums' => count($payload['album']),
            'tracks' => count($payload['track']),
        ];

        if ($dryRun) {
            return $counts;
        }

        $syncedAt = now();
        [$library, $provider] = DB::transaction(function () use ($identity, $section, $syncedAt): array {
            $server = PlexServer::query()->updateOrCreate(
                ['machine_identifier' => $identity['machine_identifier']],
                [
                    'name' => $identity['name'],
                    'machine_identifier_hash' => hash('sha256', $identity['machine_identifier']),
                    'version' => $identity['version'],
                    'last_seen_at' => $syncedAt,
                ],
            );

            $library = PlexLibrary::query()->updateOrCreate(
                ['plex_server_id' => $server->id, 'section_uuid' => $section['uuid']],
                [
                    'section_key' => $section['key'],
                    'title' => $section['title'],
                    'library_type' => $section['type'],
                    'agent' => $section['agent'],
                    'scanner' => $section['scanner'],
                    'last_synced_at' => $syncedAt,
                ],
            );

            $provider = SourceProvider::query()->firstOrCreate(
                ['slug' => 'plex'],
                [
                    'display_name' => 'Plex',
                    'enabled' => true,
                ],
            );
            $provider->update([
                'display_name' => 'Plex',
                'policy' => [
                    'storage' => 'private',
                    'connector' => 'direct_playback',
                    'writes' => ['playback_timeline', 'scrobble'],
                    'transcoding' => false,
                ],
            ]);

            return [$library, $provider];
        });

        if (! $allowEmptyTypes) {
            foreach ($payload as $type => $rows) {
                $activeTypeCount = PlexItem::query()
                    ->where('plex_library_id', $library->id)
                    ->where('item_type', $type)
                    ->whereNull('removed_at')
                    ->count();
                if ($rows === [] && $activeTypeCount > 0) {
                    throw new RuntimeException("Refusing to reconcile an empty Plex {$type} response against existing items.");
                }
            }
        }

        foreach ($payload as $type => $rows) {
            foreach (array_chunk($rows, 50) as $batch) {
                DB::transaction(function () use ($batch, $library, $provider, $syncedAt, $type): void {
                    foreach ($batch as $row) {
                        $this->syncItem($provider, $library, $type, $row, $syncedAt);
                    }
                });
            }
        }

        DB::transaction(function () use ($counts, $library, $payload, $startedAt, $syncedAt): void {
            $seenRatingKeys = [];
            foreach ($payload as $rows) {
                foreach ($rows as $row) {
                    $seenRatingKeys[] = $row['attributes']['ratingKey'];
                }
            }
            $removedItems = PlexItem::query()
                ->where('plex_library_id', $library->id)
                ->whereNull('removed_at');
            $activeItemCount = (clone $removedItems)->count();
            if ($seenRatingKeys === [] && $activeItemCount > 0) {
                throw new RuntimeException('Refusing to tombstone a non-empty Plex library after an empty scan.');
            }
            if ($seenRatingKeys !== []) {
                $removedItems->whereNotIn('rating_key', $seenRatingKeys);
            }
            $removedItems->update(['removed_at' => $syncedAt]);

            $activeHoldings = Holding::query()
                ->whereHas('plexAlbum', fn ($query) => $query->whereNull('removed_at'))
                ->orderBy('id')
                ->get()
                ->groupBy('release_group_id');
            Holding::query()
                ->whereHas('plexAlbum', fn ($query) => $query->whereNotNull('removed_at'))
                ->update(['is_primary_playback_copy' => false]);
            foreach ($activeHoldings as $group) {
                if (! $group->contains('is_primary_playback_copy', true)) {
                    $group->first()->update(['is_primary_playback_copy' => true]);
                }
            }

            PlexSyncRun::query()->create([
                'plex_library_id' => $library->id,
                'status' => 'completed',
                'counts' => [
                    'artists' => $counts['artists'],
                    'albums' => $counts['albums'],
                    'tracks' => $counts['tracks'],
                ],
                'started_at' => $startedAt,
                'completed_at' => now(),
            ]);
        });

        if (config('services.plex.artwork_auto_ingest')) {
            PlexItem::query()
                ->where('plex_library_id', $library->id)
                ->whereIn('item_type', ['artist', 'album'])
                ->pluck('id')
                ->each(fn (string $itemId) => IngestPlexArtwork::dispatch($itemId));
        }

        return $counts;
    }

    /** @param array{attributes:array<string,string>, guids:list<string>, media_parts:list<array<string,?string>>} $row */
    private function syncItem(SourceProvider $provider, PlexLibrary $library, string $type, array $row, mixed $syncedAt): void
    {
        $attributes = $row['attributes'];
        $item = PlexItem::query()->updateOrCreate(
            ['plex_library_id' => $library->id, 'rating_key' => $attributes['ratingKey']],
            [
                'item_type' => $type,
                'parent_rating_key' => $attributes['parentRatingKey'] ?? null,
                'grandparent_rating_key' => $attributes['grandparentRatingKey'] ?? null,
                'guid' => $attributes['guid'] ?? null,
                'title' => $attributes['title'],
                'sort_title' => $attributes['titleSort'] ?? null,
                'year' => $this->integer($attributes['year'] ?? null),
                'duration_ms' => $this->integer($attributes['duration'] ?? null),
                'index_number' => $this->integer($attributes['index'] ?? null),
                'disc_number' => $this->integer($attributes['parentIndex'] ?? null),
                'added_at_plex' => $this->timestamp($attributes['addedAt'] ?? null),
                'updated_at_plex' => $this->timestamp($attributes['updatedAt'] ?? null),
                'last_viewed_at' => $this->timestamp($attributes['lastViewedAt'] ?? null),
                'view_count' => $this->integer($attributes['viewCount'] ?? null),
                'thumb_key' => $attributes['thumb'] ?? null,
                'raw_metadata' => ['attributes' => $attributes, 'guids' => $row['guids']],
                'last_synced_at' => $syncedAt,
                'removed_at' => null,
            ],
        );

        $item->guids()->delete();
        foreach ($row['guids'] as $guid) {
            [$namespace, $value] = $this->splitGuid($guid);
            if ($namespace !== null) {
                PlexItemGuid::query()->create([
                    'plex_item_id' => $item->id,
                    'namespace' => $namespace,
                    'value' => $value,
                ]);
            }
        }

        $this->syncMediaParts($item, $type, $row['media_parts'], $syncedAt);

        $resolution = $this->resolveEntity($item, $type, $row['guids']);
        $entity = $resolution['entity'];
        if ($type === 'album') {
            $this->syncHolding($item, $entity);
        }
        $sourceObject = SourceObject::query()->updateOrCreate(
            [
                'provider_id' => $provider->id,
                'object_type' => $type,
                'external_id' => "{$library->plex_server_id}:{$library->section_key}:{$item->rating_key}",
            ],
            [
                'canonical_url' => null,
                'first_seen_at' => $item->wasRecentlyCreated ? $syncedAt : ($item->created_at ?? $syncedAt),
                'last_seen_at' => $syncedAt,
            ],
        );
        $payload = ['attributes' => $attributes, 'guids' => $row['guids'], 'media_parts' => $row['media_parts']];
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $payloadHash = hash('sha256', $encoded);

        $snapshot = SourceSnapshot::query()->firstOrCreate(
            ['source_object_id' => $sourceObject->id, 'payload_hash' => $payloadHash],
            [
                'retrieved_at' => $syncedAt,
                'http_status' => 200,
                'payload' => $payload,
                'parser_version' => 'plex-xml-v2',
            ],
        );
        $this->recordAssertions($snapshot, $entity, $item, $type);
        $resolvedEntities = [[
            'entity_id' => $entity->id,
            'scope' => $entity->kind,
            'status' => $resolution['status'],
            'method' => $resolution['method'],
            'confidence' => $resolution['confidence'],
        ]];
        if ($type === 'album') {
            $releaseMatch = $item->matches()
                ->where('match_scope', 'release')
                ->whereIn('status', ['confirmed', 'candidate'])
                ->first();
            if ($releaseMatch !== null) {
                $resolvedEntities[] = [
                    'entity_id' => $releaseMatch->entity_id,
                    'scope' => 'release',
                    'status' => $releaseMatch->status,
                    'method' => $releaseMatch->method,
                    'confidence' => $releaseMatch->confidence,
                ];
            }
        }
        foreach ($resolvedEntities as $resolved) {
            EntityResolution::query()
                ->where('source_object_id', $sourceObject->id)
                ->where('resolution_scope', $resolved['scope'])
                ->whereIn('status', ['confirmed', 'candidate'])
                ->where('entity_id', '!=', $resolved['entity_id'])
                ->update(['status' => 'superseded']);
            EntityResolution::query()->updateOrCreate(
                [
                    'source_object_id' => $sourceObject->id,
                    'entity_id' => $resolved['entity_id'],
                    'resolution_scope' => $resolved['scope'],
                ],
                [
                    'status' => $resolved['status'],
                    'method' => $resolved['method'],
                    'confidence' => $resolved['confidence'],
                    'algorithm_version' => 'plex-v2',
                    'evidence' => [
                        'plex_item_id' => $item->id,
                        'snapshot_payload_hash' => $payloadHash,
                    ],
                ],
            );
        }
    }

    /**
     * @param  list<string>  $guids
     * @return array{entity:CatalogEntity,status:string,method:string,confidence:float|int}
     */
    private function resolveEntity(PlexItem $item, string $type, array $guids): array
    {
        if ($type === 'album') {
            return $this->resolveAlbum($item, $guids);
        }

        $kind = match ($type) {
            'artist' => 'agent',
            'track' => 'recording',
            default => throw new RuntimeException("Unsupported Plex item type [{$type}]."),
        };
        $manual = $item->matches()
            ->where('match_scope', $kind)
            ->where('method', 'manual')
            ->where('status', 'confirmed')
            ->first();
        if ($manual !== null) {
            return [
                'entity' => $manual->entity,
                'status' => 'confirmed',
                'method' => 'manual',
                'confidence' => 1,
            ];
        }

        $namespace = match ($kind) {
            'agent' => 'musicbrainz.artist',
            'recording' => 'musicbrainz.recording',
        };
        $mbid = $this->singleMbid($guids);
        if ($kind === 'agent' && $mbid !== null) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["disco:musicbrainz-artist:{$mbid}"]);
        }
        $currentMatch = $item->matches()
            ->where('match_scope', $kind)
            ->whereIn('status', ['confirmed', 'candidate'])
            ->where('method', '!=', 'manual')
            ->with('entity')
            ->first();
        $identifiedEntity = $mbid === null
            ? null
            : ExternalIdentifier::query()->where('namespace', $namespace)->where('value', $mbid)->first()?->entity;

        $currentIdentifiers = $currentMatch === null
            ? collect()
            : ExternalIdentifier::query()
                ->where('entity_id', $currentMatch->entity_id)
                ->where('namespace', $namespace)
                ->pluck('value');
        $canReuseCurrent = $currentMatch !== null
            && ($mbid === null || $currentIdentifiers->isEmpty() || $currentIdentifiers->contains($mbid));
        $entity = $identifiedEntity ?? ($canReuseCurrent ? $currentMatch->entity : null);

        $entity ??= CatalogEntity::query()->create([
            'kind' => $kind,
            'status' => 'active',
            'canonical_name' => $item->title,
            'sort_name' => $item->sort_title ?: $item->title,
        ]);

        if ($mbid !== null) {
            ExternalIdentifier::query()->firstOrCreate(
                ['namespace' => $namespace, 'value' => $mbid],
                ['entity_id' => $entity->id, 'status' => 'active'],
            );
        }

        match ($kind) {
            'agent' => Agent::query()->updateOrCreate(['entity_id' => $entity->id], ['agent_type' => 'other']),
            'release_group' => ReleaseGroup::query()->updateOrCreate(
                ['entity_id' => $entity->id],
                [
                    'primary_type' => 'album',
                    'first_release_year' => $item->year,
                    'date_precision' => $item->year ? 'year' : 'unknown',
                ],
            ),
            'recording' => Recording::query()->updateOrCreate(
                ['entity_id' => $entity->id],
                ['duration_ms' => $item->duration_ms],
            ),
        };

        $preserveConfirmed = $mbid === null && $currentMatch?->status === 'confirmed';
        $status = $mbid === null && ! $preserveConfirmed ? 'candidate' : 'confirmed';
        $method = $mbid === null ? ($currentMatch?->method ?? 'plex_library_item') : 'external_id';
        $confidence = $mbid === null ? (float) ($currentMatch?->confidence ?? 0.5) : 1;
        $item->matches()
            ->where('match_scope', $kind)
            ->where('method', '!=', 'manual')
            ->whereIn('status', ['confirmed', 'candidate'])
            ->where('entity_id', '!=', $entity->id)
            ->update(['status' => 'superseded']);
        PlexEntityMatch::query()->updateOrCreate(
            [
                'plex_item_id' => $item->id,
                'entity_id' => $entity->id,
                'match_scope' => $kind,
            ],
            compact('status', 'method', 'confidence'),
        );

        return compact('entity', 'status', 'method', 'confidence');
    }

    /**
     * @param  list<string>  $guids
     * @return array{entity:CatalogEntity,status:string,method:string,confidence:float|int}
     */
    private function resolveAlbum(PlexItem $item, array $guids): array
    {
        $mbid = $this->singleMbid($guids);
        $manualMatch = $item->matches()
            ->where('match_scope', 'release_group')
            ->where('method', 'manual')
            ->where('status', 'confirmed')
            ->with('entity')
            ->first();
        $manualReleaseMatch = $item->matches()
            ->where('match_scope', 'release')
            ->where('method', 'manual')
            ->where('status', 'confirmed')
            ->with('entity.release')
            ->first();
        $manualRelease = $manualReleaseMatch?->entity?->release;
        $currentMatch = $manualMatch ?? $item->matches()
            ->where('match_scope', 'release_group')
            ->whereIn('status', ['confirmed', 'candidate'])
            ->with('entity')
            ->first();
        $identifier = $mbid === null
            ? null
            : ExternalIdentifier::query()
                ->where('namespace', 'musicbrainz.release')
                ->where('value', $mbid)
                ->with('entity.release')
                ->first();
        if ($identifier !== null && ($identifier->status !== 'active' || $identifier->entity?->kind !== 'release')) {
            throw new RuntimeException("MusicBrainz release [{$mbid}] is not an active release identity.");
        }
        $identifiedRelease = $identifier?->entity?->kind === 'release'
            ? $identifier->entity->release
            : null;
        $identifiedGroup = $identifiedRelease === null
            ? null
            : CatalogEntity::query()->find($identifiedRelease->release_group_id);
        $manualReleaseGroup = $manualRelease === null
            ? null
            : CatalogEntity::query()->find($manualRelease->release_group_id);
        if ($manualMatch !== null && $manualRelease !== null && $manualRelease->release_group_id !== $manualMatch->entity_id) {
            throw new RuntimeException('The manual album and edition matches belong to different release groups.');
        }
        if ($manualMatch !== null && $manualRelease === null && $identifiedRelease !== null
            && $identifiedRelease->release_group_id !== $manualMatch->entity_id) {
            throw new RuntimeException("MusicBrainz release [{$mbid}] conflicts with the manual album match.");
        }
        $entity = $manualMatch?->entity
            ?? $manualReleaseGroup
            ?? $identifiedGroup
            ?? $currentMatch?->entity;
        $entity ??= CatalogEntity::query()->create([
            'kind' => 'release_group',
            'status' => 'active',
            'canonical_name' => $item->title,
            'sort_name' => $item->sort_title ?: $item->title,
        ]);
        ReleaseGroup::query()->updateOrCreate(
            ['entity_id' => $entity->id],
            [
                'primary_type' => 'album',
                'first_release_year' => $item->year,
                'date_precision' => $item->year ? 'year' : 'unknown',
            ],
        );

        $hasManualIdentity = $manualMatch !== null || $manualRelease !== null;
        $preserveConfirmed = $mbid === null && $currentMatch?->status === 'confirmed';
        $status = $mbid === null && ! $preserveConfirmed && ! $hasManualIdentity ? 'candidate' : 'confirmed';
        $method = $manualMatch !== null
            ? 'manual'
            : ($manualRelease !== null
                ? 'release_parent'
                : ($mbid === null ? ($currentMatch?->method ?? 'plex_library_item') : 'release_parent'));
        $confidence = $mbid === null && ! $hasManualIdentity ? (float) ($currentMatch?->confidence ?? 0.5) : 1;
        $item->matches()
            ->where('match_scope', 'release_group')
            ->where('method', '!=', 'manual')
            ->whereIn('status', ['confirmed', 'candidate'])
            ->where('entity_id', '!=', $entity->id)
            ->update(['status' => 'superseded']);
        PlexEntityMatch::query()->updateOrCreate(
            ['plex_item_id' => $item->id, 'entity_id' => $entity->id, 'match_scope' => 'release_group'],
            compact('status', 'method', 'confidence'),
        );

        if ($manualRelease !== null) {
            $identifiedRelease = $manualRelease;
        } elseif ($mbid !== null) {
            if ($identifiedRelease === null) {
                $releaseEntity = CatalogEntity::query()->create([
                    'kind' => 'release',
                    'status' => 'active',
                    'canonical_name' => $item->title,
                    'sort_name' => $item->sort_title ?: $item->title,
                ]);
                $identifiedRelease = Release::query()->create([
                    'entity_id' => $releaseEntity->id,
                    'release_group_id' => $entity->id,
                    'status' => 'unknown',
                ]);
                ExternalIdentifier::query()->create([
                    'entity_id' => $releaseEntity->id,
                    'namespace' => 'musicbrainz.release',
                    'value' => $mbid,
                    'status' => 'active',
                ]);
            }
        }
        if ($identifiedRelease !== null) {
            $item->matches()
                ->where('match_scope', 'release')
                ->where('method', '!=', 'manual')
                ->whereIn('status', ['confirmed', 'candidate'])
                ->where('entity_id', '!=', $identifiedRelease->entity_id)
                ->update(['status' => 'superseded']);
            if ($manualReleaseMatch === null) {
                PlexEntityMatch::query()->updateOrCreate(
                    [
                        'plex_item_id' => $item->id,
                        'entity_id' => $identifiedRelease->entity_id,
                        'match_scope' => 'release',
                    ],
                    ['status' => 'confirmed', 'method' => 'external_id', 'confidence' => 1],
                );
            }
        }

        return compact('entity', 'status', 'method', 'confidence');
    }

    /** @param list<string> $guids */
    private function singleMbid(array $guids): ?string
    {
        $mbids = collect($guids)
            ->filter(fn (string $guid): bool => str_starts_with($guid, 'mbid://'))
            ->map(fn (string $guid): string => strtolower(Str::after($guid, 'mbid://')))
            ->filter(fn (string $mbid): bool => Str::isUuid($mbid))
            ->unique()
            ->values();

        return $mbids->count() === 1 ? $mbids->first() : null;
    }

    private function syncHolding(PlexItem $album, CatalogEntity $releaseGroup): void
    {
        $releaseMatch = $album->matches()
            ->where('match_scope', 'release')
            ->where('status', 'confirmed')
            ->whereHas('entity.release', fn ($query) => $query->where('release_group_id', $releaseGroup->id))
            ->orderByRaw("case when method = 'manual' then 0 when status = 'confirmed' then 1 else 2 end")
            ->with('entity')
            ->first();
        $holding = Holding::query()->where('plex_album_item_id', $album->id)->first();
        $hasAnotherPrimary = Holding::query()
            ->where('release_group_id', $releaseGroup->id)
            ->where('is_primary_playback_copy', true)
            ->when($holding !== null, fn ($query) => $query->where('id', '!=', $holding->id))
            ->exists();
        Holding::query()->updateOrCreate(
            ['plex_album_item_id' => $album->id],
            [
                'release_group_id' => $releaseGroup->id,
                'release_id' => $releaseMatch?->entity_id,
                'ownership_type' => 'digital',
                'is_primary_playback_copy' => ! $hasAnotherPrimary,
            ],
        );
    }

    private function recordAssertions(
        SourceSnapshot $snapshot,
        CatalogEntity $entity,
        PlexItem $item,
        string $type,
    ): void {
        $assertions = [
            'catalog.entities.canonical_name' => $item->title,
            'catalog.entities.sort_name' => $item->sort_title ?: $item->title,
        ];
        if ($type === 'album') {
            $assertions['catalog.release_groups.first_release_year'] = $item->year;
            $assertions['library.holdings.plex_item_id'] = $item->id;
        } elseif ($type === 'track') {
            $assertions['catalog.recordings.duration_ms'] = $item->duration_ms;
        }

        foreach ($assertions as $predicate => $value) {
            SourceAssertion::query()->firstOrCreate(
                [
                    'snapshot_id' => $snapshot->id,
                    'subject_entity_id' => $entity->id,
                    'predicate' => $predicate,
                ],
                [
                    'value' => ['observed' => $value],
                    'status' => 'observed',
                    'confidence' => 1,
                ],
            );
        }
    }

    /** @return array{0:?string,1:string} */
    private function splitGuid(string $guid): array
    {
        if (! str_contains($guid, '://')) {
            return [null, $guid];
        }

        [$namespace, $value] = explode('://', $guid, 2);

        return [$namespace, $value];
    }

    /** @param list<array<string, ?string>> $parts */
    private function syncMediaParts(PlexItem $item, string $type, array $parts, mixed $syncedAt): void
    {
        if ($type !== 'track') {
            return;
        }

        $seen = [];
        foreach ($parts as $part) {
            $mediaId = $part['media_id'] ?? null;
            $partId = $part['part_id'] ?? null;
            $partKey = $part['part_key'] ?? null;
            $size = $this->boundedInteger($part['size_bytes'] ?? null, 1, 64 * 1024 * 1024 * 1024);
            if (! is_string($mediaId) || preg_match('/\A[1-9][0-9]{0,18}\z/D', $mediaId) !== 1
                || ! is_string($partId) || preg_match('/\A[1-9][0-9]{0,18}\z/D', $partId) !== 1
                || ! is_string($partKey) || ! $this->validPartKey($partKey, $partId)
                || $size === null) {
                continue;
            }

            $attributes = [
                'part_key' => $partKey,
                'container' => $this->mediaName($part['container'] ?? null),
                'audio_codec' => $this->mediaName($part['audio_codec'] ?? null),
                'channels' => $this->boundedInteger($part['channels'] ?? null, 1, 32),
                'bit_depth' => $this->boundedInteger($part['bit_depth'] ?? null, 1, 64),
                'sample_rate_hz' => $this->boundedInteger($part['sample_rate_hz'] ?? null, 1000, 768000),
                'bitrate_kbps' => $this->boundedInteger($part['bitrate_kbps'] ?? null, 1, 100000),
                'size_bytes' => $size,
                'duration_ms' => $this->boundedInteger($part['duration_ms'] ?? null, 1, 24 * 60 * 60 * 1000),
                'last_synced_at' => $syncedAt,
            ];
            $attributes['media_version'] = hash('sha256', json_encode([
                $attributes['part_key'], $attributes['container'], $attributes['audio_codec'],
                $attributes['channels'], $attributes['bit_depth'], $attributes['sample_rate_hz'],
                $attributes['size_bytes'], $attributes['duration_ms'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $stored = PlexMediaPart::query()->updateOrCreate([
                'plex_item_id' => $item->id,
                'media_id' => $mediaId,
                'part_id' => $partId,
            ], $attributes);
            $seen[] = $stored->id;
        }

        $stale = $item->mediaParts();
        if ($seen !== []) {
            $stale->whereNotIn('id', $seen);
        }
        $stale->delete();
    }

    private function validPartKey(string $key, string $partId): bool
    {
        return preg_match('#\A/library/parts/([1-9][0-9]{0,18})/[1-9][0-9]{0,18}/file(?:\.[A-Za-z0-9]{1,10})?\z#D', $key, $matches) === 1
            && hash_equals($partId, $matches[1]);
    }

    private function mediaName(?string $value): ?string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        return $value !== '' && preg_match('/\A[a-z0-9][a-z0-9._-]{0,31}\z/D', $value) === 1 ? $value : null;
    }

    private function boundedInteger(?string $value, int $minimum, int $maximum): ?int
    {
        if (! is_string($value) || preg_match('/\A[0-9]+\z/D', $value) !== 1) {
            return null;
        }
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum, 'max_range' => $maximum]]);

        return is_int($number) ? $number : null;
    }

    private function integer(?string $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function timestamp(?string $value): ?CarbonImmutable
    {
        return $value === null || $value === ''
            ? null
            : CarbonImmutable::createFromTimestampUTC((int) $value);
    }
}
