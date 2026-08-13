<?php

namespace App\Music\MusicBrainz;

use App\Models\CanonicalFieldChoice;
use App\Models\CatalogEntity;
use App\Models\EntityMetadata;
use App\Models\EntityResolution;
use App\Models\ExternalIdentifier;
use App\Models\Holding;
use App\Models\PlexEntityMatch;
use App\Models\Release;
use App\Models\ReleaseGroup;
use App\Models\SourceAssertion;
use App\Models\SourceObject;
use App\Models\SourceProvider;
use App\Models\SourceSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MusicBrainzEnricher
{
    public function __construct(
        private readonly MusicBrainzClient $client,
        private readonly MusicBrainzTracklistProjector $tracklists,
    ) {}

    public function enrich(ExternalIdentifier $identifier): EntityMetadata
    {
        $type = match ($identifier->namespace) {
            'musicbrainz.artist' => 'artist',
            'musicbrainz.release' => 'release',
            'musicbrainz.release_group' => 'release-group',
            default => throw new \RuntimeException('Unsupported MusicBrainz identifier namespace.'),
        };
        $payload = $this->client->entity($type, $identifier->value);
        $values = match ($type) {
            'artist' => $this->artistValues($payload),
            'release' => $this->releaseValues($payload),
            'release-group' => $this->releaseGroupValues($payload),
        };

        $metadata = DB::transaction(function () use ($identifier, $payload, $type, $values): EntityMetadata {
            $provider = SourceProvider::query()->firstOrCreate(
                ['slug' => 'musicbrainz'],
                [
                    'display_name' => 'MusicBrainz',
                    'enabled' => true,
                    'policy' => ['storage' => 'metadata', 'connector' => 'read_only', 'license' => 'CC0'],
                ],
            );
            $now = now();
            $object = SourceObject::query()->firstOrCreate(
                ['provider_id' => $provider->id, 'object_type' => $type, 'external_id' => $identifier->value],
                [
                    'canonical_url' => "https://musicbrainz.org/{$type}/{$identifier->value}",
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ],
            );
            $object->update(['canonical_url' => "https://musicbrainz.org/{$type}/{$identifier->value}", 'last_seen_at' => $now]);
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $snapshot = SourceSnapshot::query()->firstOrCreate(
                ['source_object_id' => $object->id, 'payload_hash' => hash('sha256', $encoded)],
                [
                    'retrieved_at' => $now,
                    'http_status' => 200,
                    'payload' => $payload,
                    'parser_version' => 'musicbrainz-json-v1',
                    'expires_at' => $now->copy()->addDays(30),
                ],
            );
            $metadata = $this->promote($snapshot, $identifier->entity_id, $values, $now);
            if ($type === 'release') {
                $this->projectRelease($identifier, $payload, $snapshot, $now);
            }

            return $metadata;
        });
        if ($type === 'release' && is_array($payload['media'] ?? null) && $payload['media'] !== []) {
            $this->tracklists->project(Release::query()->findOrFail($identifier->entity_id), $payload);
        }

        return $metadata;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function promote(SourceSnapshot $snapshot, string $entityId, array $values, mixed $now): EntityMetadata
    {
        foreach ($values as $predicate => $value) {
            $assertion = SourceAssertion::query()->firstOrCreate(
                [
                    'snapshot_id' => $snapshot->id,
                    'subject_entity_id' => $entityId,
                    'predicate' => "catalog.entity_metadata.{$predicate}",
                ],
                ['value' => ['observed' => $value], 'status' => 'observed', 'confidence' => 1],
            );
            CanonicalFieldChoice::query()->updateOrCreate(
                ['entity_id' => $entityId, 'predicate' => "catalog.entity_metadata.{$predicate}"],
                ['assertion_id' => $assertion->id, 'selected_by' => 'musicbrainz_owned_mbid'],
            );
        }

        return EntityMetadata::query()->updateOrCreate(
            ['entity_id' => $entityId],
            ['source_provider' => 'musicbrainz', ...$values, 'enriched_at' => $now],
        );
    }

    /** @param array<string, mixed> $payload */
    private function projectRelease(
        ExternalIdentifier $identifier,
        array $payload,
        SourceSnapshot $snapshot,
        mixed $now,
    ): void {
        $release = Release::query()->findOrFail($identifier->entity_id);
        $releaseDate = $this->partialDateValues('release', $payload['date'] ?? null);
        $release->update([
            'status' => strtolower((string) ($payload['status'] ?? 'unknown')),
            'country_code' => $payload['country'] ?? null,
            'barcode' => $payload['barcode'] ?? null,
            'release_year' => $releaseDate['release_year'],
            'release_month' => $releaseDate['release_month'],
            'release_day' => $releaseDate['release_day'],
            'date_precision' => $releaseDate['release_precision'] ?? 'unknown',
            'edition_summary' => $payload['disambiguation'] ?? null,
        ]);

        $groupPayload = is_array($payload['release-group'] ?? null) ? $payload['release-group'] : [];
        $groupMbid = $groupPayload['id'] ?? null;
        if (! is_string($groupMbid) || ! Str::isUuid($groupMbid)) {
            throw new \RuntimeException('MusicBrainz returned a release without a valid release-group identity.');
        }
        $groupMbid = strtolower($groupMbid);
        $groupIdentifier = ExternalIdentifier::query()
            ->where('namespace', 'musicbrainz.release_group')
            ->where('value', $groupMbid)
            ->first();
        if ($groupIdentifier !== null && $groupIdentifier->status !== 'active') {
            throw new \RuntimeException("MusicBrainz release group [{$groupMbid}] is inactive and requires review.");
        }
        if ($groupIdentifier !== null && $groupIdentifier->entity_id !== $release->release_group_id) {
            $release = $this->moveReleaseToGroup($release, $groupIdentifier->entity_id);
        }
        if ($groupIdentifier === null) {
            ExternalIdentifier::query()->create([
                'namespace' => 'musicbrainz.release_group',
                'value' => $groupMbid,
                'entity_id' => $release->release_group_id,
                'status' => 'active',
            ]);
        } else {
            $groupIdentifier->update(['status' => 'active']);
        }

        $firstRelease = $this->partialDateValues('first_release', $groupPayload['first-release-date'] ?? null);
        ReleaseGroup::query()->updateOrCreate(
            ['entity_id' => $release->release_group_id],
            [
                'primary_type' => strtolower((string) ($groupPayload['primary-type'] ?? 'album')),
                'secondary_types' => $groupPayload['secondary-types'] ?? [],
                'first_release_year' => $firstRelease['first_release_year'],
                'first_release_month' => $firstRelease['first_release_month'],
                'first_release_day' => $firstRelease['first_release_day'],
                'date_precision' => $firstRelease['first_release_precision'] ?? 'unknown',
            ],
        );
        $groupValues = $this->releaseGroupValues($groupPayload);
        $existingGroupMetadata = EntityMetadata::query()->find($release->release_group_id);
        $groupValues['external_links'] = collect([
            ...($existingGroupMetadata?->external_links ?? []),
            ...$groupValues['external_links'],
            ...$this->externalLinks($payload),
        ])->unique('url')->values()->all();
        if (! array_key_exists('genres', $groupPayload)) {
            $groupValues['genres'] = $existingGroupMetadata?->genres ?? [];
        }
        if (! array_key_exists('artist-credit', $groupPayload)) {
            $groupValues['artist_credit'] = $existingGroupMetadata?->artist_credit ?? [];
        }
        if (! array_key_exists('disambiguation', $groupPayload)) {
            $groupValues['disambiguation'] = $existingGroupMetadata?->disambiguation;
        }
        if (! array_key_exists('first-release-date', $groupPayload) && $existingGroupMetadata !== null) {
            foreach (['year', 'month', 'day', 'precision'] as $part) {
                $groupValues["first_release_{$part}"] = $existingGroupMetadata->{"first_release_{$part}"};
            }
        }
        $groupAttributes = [
            'secondary_types' => $groupPayload['secondary-types']
                ?? $existingGroupMetadata?->attributes['secondary_types']
                ?? [],
        ];
        if (array_key_exists('releases', $groupPayload)) {
            $groupAttributes['release_count'] = count($groupPayload['releases']);
        } elseif (isset($existingGroupMetadata?->attributes['release_count'])) {
            $groupAttributes['release_count'] = $existingGroupMetadata->attributes['release_count'];
        }
        $groupValues['attributes'] = $groupAttributes;
        $this->promote($snapshot, $release->release_group_id, $groupValues, $now);
    }

    private function moveReleaseToGroup(Release $release, string $targetGroupId): Release
    {
        $target = CatalogEntity::query()
            ->whereKey($targetGroupId)
            ->where('kind', 'release_group')
            ->where('status', 'active')
            ->first();
        if ($target === null) {
            throw new \RuntimeException('MusicBrainz release-group identity points to an unavailable catalog entity.');
        }
        $currentGroupId = $release->release_group_id;
        $currentGroupIdentifier = ExternalIdentifier::query()
            ->where('entity_id', $currentGroupId)
            ->where('namespace', 'musicbrainz.release_group')
            ->where('status', 'active')
            ->first();
        if ($currentGroupIdentifier !== null) {
            throw new \RuntimeException('MusicBrainz release conflicts with the existing release-group identity.');
        }

        $plexItemIds = PlexEntityMatch::query()
            ->where('entity_id', $release->entity_id)
            ->where('match_scope', 'release')
            ->whereIn('status', ['confirmed', 'candidate'])
            ->pluck('plex_item_id');
        $manualConflict = PlexEntityMatch::query()
            ->whereIn('plex_item_id', $plexItemIds)
            ->where('match_scope', 'release_group')
            ->where('method', 'manual')
            ->where('status', 'confirmed')
            ->where('entity_id', '!=', $targetGroupId)
            ->exists();
        if ($manualConflict) {
            throw new \RuntimeException('MusicBrainz release conflicts with a manual album match.');
        }

        $holdingIds = Holding::query()->where('release_id', $release->entity_id)->pluck('id');
        Holding::query()->whereIn('id', $holdingIds)->update(['release_id' => null]);
        $release->update(['release_group_id' => $targetGroupId]);

        foreach ($plexItemIds as $plexItemId) {
            PlexEntityMatch::query()
                ->where('plex_item_id', $plexItemId)
                ->where('match_scope', 'release_group')
                ->where('method', '!=', 'manual')
                ->whereIn('status', ['confirmed', 'candidate'])
                ->where('entity_id', '!=', $targetGroupId)
                ->update(['status' => 'superseded']);
            $targetMatch = PlexEntityMatch::query()
                ->where('plex_item_id', $plexItemId)
                ->where('entity_id', $targetGroupId)
                ->where('match_scope', 'release_group')
                ->first();
            if ($targetMatch === null) {
                PlexEntityMatch::query()->create([
                    'plex_item_id' => $plexItemId,
                    'entity_id' => $targetGroupId,
                    'match_scope' => 'release_group',
                    'status' => 'confirmed',
                    'method' => 'release_parent',
                    'confidence' => 1,
                ]);
            } elseif ($targetMatch->method !== 'manual') {
                $targetMatch->update(['status' => 'confirmed', 'method' => 'release_parent', 'confidence' => 1]);
            }
        }

        if (Holding::query()->where('release_group_id', $targetGroupId)->where('is_primary_playback_copy', true)->exists()) {
            Holding::query()->whereIn('id', $holdingIds)->update(['is_primary_playback_copy' => false]);
        }
        Holding::query()->whereIn('id', $holdingIds)->update([
            'release_group_id' => $targetGroupId,
            'release_id' => $release->entity_id,
        ]);

        $resolutions = EntityResolution::query()
            ->where('entity_id', $currentGroupId)
            ->where('resolution_scope', 'release_group')
            ->whereIn('status', ['confirmed', 'candidate'])
            ->whereIn(DB::raw("evidence->>'plex_item_id'"), $plexItemIds)
            ->get();
        foreach ($resolutions as $resolution) {
            $targetResolutionExists = EntityResolution::query()
                ->where('source_object_id', $resolution->source_object_id)
                ->where('entity_id', $targetGroupId)
                ->where('resolution_scope', 'release_group')
                ->whereIn('status', ['confirmed', 'candidate'])
                ->exists();
            $resolution->update($targetResolutionExists
                ? ['status' => 'superseded']
                : ['entity_id' => $targetGroupId]);
        }

        if (! Release::query()->where('release_group_id', $currentGroupId)->exists()
            && ! Holding::query()->where('release_group_id', $currentGroupId)->exists()) {
            CatalogEntity::query()->whereKey($currentGroupId)->update([
                'status' => 'redirected',
                'redirect_entity_id' => $targetGroupId,
            ]);
        }

        return $release->refresh();
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function artistValues(array $payload): array
    {
        return [
            'genres' => $this->genres($payload),
            'primary_type' => $payload['type'] ?? null,
            'country_code' => $payload['country'] ?? null,
            'area' => data_get($payload, 'area.name'),
            ...$this->partialDateValues('begin', data_get($payload, 'life-span.begin')),
            ...$this->partialDateValues('end', data_get($payload, 'life-span.end')),
            ...$this->partialDateValues('first_release', null),
            'disambiguation' => $payload['disambiguation'] ?? null,
            'artist_credit' => [],
            'external_links' => $this->externalLinks($payload),
            'attributes' => ['ended' => (bool) data_get($payload, 'life-span.ended', false)],
        ];
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function releaseGroupValues(array $payload): array
    {
        return [
            'genres' => $this->genres($payload),
            'primary_type' => $payload['primary-type'] ?? null,
            'country_code' => null,
            'area' => null,
            ...$this->partialDateValues('begin', null),
            ...$this->partialDateValues('end', null),
            ...$this->partialDateValues('first_release', $payload['first-release-date'] ?? null),
            'disambiguation' => $payload['disambiguation'] ?? null,
            'artist_credit' => collect($payload['artist-credit'] ?? [])->map(fn (array $credit): array => [
                'name' => $credit['name'] ?? data_get($credit, 'artist.name'),
                'artist_mbid' => data_get($credit, 'artist.id'),
                'artist_entity_id' => $this->artistEntityId(data_get($credit, 'artist.id')),
                'joinphrase' => $credit['joinphrase'] ?? '',
            ])->values()->all(),
            'external_links' => $this->externalLinks($payload),
            'attributes' => [
                'secondary_types' => $payload['secondary-types'] ?? [],
                'release_count' => count($payload['releases'] ?? []),
            ],
        ];
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function releaseValues(array $payload): array
    {
        $releaseGroup = is_array($payload['release-group'] ?? null) ? $payload['release-group'] : [];

        return [
            'genres' => [],
            'primary_type' => $releaseGroup['primary-type'] ?? null,
            'country_code' => $payload['country'] ?? null,
            'area' => null,
            ...$this->partialDateValues('begin', null),
            ...$this->partialDateValues('end', null),
            ...$this->partialDateValues('first_release', $releaseGroup['first-release-date'] ?? $payload['date'] ?? null),
            'disambiguation' => $releaseGroup['disambiguation'] ?? $payload['disambiguation'] ?? null,
            'artist_credit' => collect($releaseGroup['artist-credit'] ?? $payload['artist-credit'] ?? [])->map(fn (array $credit): array => [
                'name' => $credit['name'] ?? data_get($credit, 'artist.name'),
                'artist_mbid' => data_get($credit, 'artist.id'),
                'artist_entity_id' => $this->artistEntityId(data_get($credit, 'artist.id')),
                'joinphrase' => $credit['joinphrase'] ?? '',
            ])->values()->all(),
            'external_links' => $this->externalLinks($payload),
            'attributes' => [
                'basis_release_mbid' => $payload['id'],
                'release_group_mbid' => $releaseGroup['id'] ?? null,
                'secondary_types' => $releaseGroup['secondary-types'] ?? [],
                'edition_date' => $payload['date'] ?? null,
                'edition_status' => $payload['status'] ?? null,
                'packaging' => $payload['packaging'] ?? null,
                'labels' => collect($payload['label-info'] ?? [])->map(function (array $info): array {
                    $catalogNumber = $info['catalog-number'] ?? null;
                    if (is_string($catalogNumber) && in_array(strtolower(trim($catalogNumber)), ['[none]', '[no catalog number]'], true)) {
                        $catalogNumber = null;
                    }

                    return [
                        'name' => data_get($info, 'label.name'),
                        'label_mbid' => data_get($info, 'label.id'),
                        'catalog_number' => $catalogNumber,
                    ];
                })->filter(fn (array $label): bool => $label['name'] !== null && ! in_array(
                    strtolower(trim((string) $label['name'])),
                    ['[none]', '[no label]'],
                    true,
                ))->values()->all(),
            ],
        ];
    }

    /** @param array<string, mixed> $payload
     * @return list<array{type:mixed,url:string}>
     */
    private function externalLinks(array $payload): array
    {
        return collect($payload['relations'] ?? [])
            ->where('target-type', 'url')
            ->map(fn (array $relation): array => [
                'type' => $relation['type'] ?? 'other',
                'url' => data_get($relation, 'url.resource'),
            ])
            ->filter(fn (array $link): bool => is_string($link['url']) && str_starts_with($link['url'], 'https://'))
            ->values()
            ->all();
    }

    private function artistEntityId(mixed $mbid): ?string
    {
        if (! is_string($mbid) || ! Str::isUuid($mbid)) {
            return null;
        }
        $entity = ExternalIdentifier::query()->where('namespace', 'musicbrainz.artist')
            ->where('value', strtolower($mbid))->where('status', 'active')->first()?->entity;

        return $entity?->kind === 'agent' && $entity->status === 'active' ? $entity->id : null;
    }

    /** @param array<string, mixed> $payload
     * @return list<array{name:string,count:int,source:string}>
     */
    private function genres(array $payload): array
    {
        return collect($payload['genres'] ?? [])
            ->filter(fn (array $genre): bool => isset($genre['name']))
            ->map(fn (array $genre): array => [
                'name' => (string) $genre['name'],
                'count' => (int) ($genre['count'] ?? 0),
                'source' => 'musicbrainz',
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /** @return array<string, int|string|null> */
    private function partialDateValues(string $prefix, mixed $value): array
    {
        $parts = is_string($value) && preg_match('/\A([0-9]{1,4})(?:-([0-9]{2})(?:-([0-9]{2}))?)?\z/', $value, $matches) === 1
            ? $matches
            : [];
        $precision = isset($parts[3]) ? 'day' : (isset($parts[2]) ? 'month' : (isset($parts[1]) ? 'year' : null));

        return [
            "{$prefix}_year" => isset($parts[1]) ? (int) $parts[1] : null,
            "{$prefix}_month" => isset($parts[2]) ? (int) $parts[2] : null,
            "{$prefix}_day" => isset($parts[3]) ? (int) $parts[3] : null,
            "{$prefix}_precision" => $precision,
        ];
    }
}
