<?php

namespace App\Music\Discovery;

use App\Models\Agent;
use App\Models\ArtistDiscographyGeneration;
use App\Models\ArtistDiscographyItem;
use App\Models\CatalogEntity;
use App\Models\EntityMetadata;
use App\Models\ExternalIdentifier;
use App\Models\Release;
use App\Models\ReleaseGroup;
use App\Music\CanonicalEntityResolver;
use App\Music\MusicBrainz\MusicBrainzClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ArtistDiscographyRefresher
{
    private const ALGORITHM = 'musicbrainz-artist-rg-v1';

    public function __construct(
        private readonly MusicBrainzClient $musicBrainz,
        private readonly CanonicalEntityResolver $canonicalEntities,
    ) {}

    /** @return array{generation_id:string,artist_id:string,items:int,source_total:int,pages:int,truncated:bool} */
    public function refresh(string $artistId): array
    {
        $artist = $this->canonicalEntities->resolve($artistId, 'agent');
        if ($artist === null) {
            throw new RuntimeException('Artist is not an active canonical agent.');
        }
        $mbids = ExternalIdentifier::query()
            ->where('entity_id', $artist->id)
            ->where('namespace', 'musicbrainz.artist')
            ->where('status', 'active')
            ->pluck('value')
            ->filter(fn (mixed $value): bool => is_string($value) && Str::isUuid($value))
            ->map(fn (string $value): string => strtolower($value))
            ->unique()
            ->values();
        if ($mbids->count() !== 1) {
            throw new RuntimeException('Artist requires exactly one active MusicBrainz artist identity.');
        }
        $artistMbid = $mbids->first();
        $lock = Cache::lock("disco:artist-discography:{$artist->id}", 3600);
        if (! $lock->get()) {
            throw new RuntimeException('Another discography refresh is already running for this artist.');
        }

        try {
            return $this->performRefresh($artist, $artistMbid);
        } finally {
            $lock->release();
        }
    }

    /** @return array{generation_id:string,artist_id:string,items:int,source_total:int,pages:int,truncated:bool} */
    private function performRefresh(CatalogEntity $artist, string $artistMbid): array
    {
        $browse = $this->musicBrainz->artistReleaseGroups(
            $artistMbid,
            (int) config('discovery.discography.page_size', 100),
            (int) config('discovery.discography.max_pages', 3),
        );
        $candidates = [];
        foreach ($browse['release_groups'] as $group) {
            $credits = $this->validatedCredits($group['artist-credit'] ?? null, $artistMbid);
            if ($credits === null) {
                continue;
            }
            if (! is_string($group['title'] ?? null) || trim($group['title']) === '' || strlen($group['title']) > 255) {
                throw new RuntimeException('MusicBrainz returned an invalid artist release-group credit or title.');
            }
            $groupMbid = strtolower((string) $group['id']);
            $embeddedReleases = $group['releases'] ?? [];
            $secondaryTypes = $group['secondary-types'] ?? [];
            if (! is_array($embeddedReleases) || count($embeddedReleases) > 100 || ! is_array($secondaryTypes) || count($secondaryTypes) > 20
                || collect($secondaryTypes)->contains(fn (mixed $type): bool => ! is_string($type) || strlen($type) > 80)) {
                throw new RuntimeException('MusicBrainz returned an oversized artist release-group record.');
            }
            $official = collect($embeddedReleases)->first(
                fn (mixed $release): bool => is_array($release)
                    && Str::isUuid($release['id'] ?? null)
                    && strtolower((string) ($release['status'] ?? '')) === 'official',
            );
            if (! is_array($official)) {
                $official = $this->musicBrainz->officialRelease(
                    $groupMbid,
                    100,
                    (int) config('discovery.discography.official_release_max_pages', 2),
                );
            }
            if ($official === null) {
                continue;
            }
            $secondary = collect($secondaryTypes)
                ->filter(fn (mixed $type): bool => is_string($type))->map(fn (string $type): string => strtolower(trim($type)))->filter()->unique()->values()->all();
            $candidates[$groupMbid] = [
                'mbid' => $groupMbid,
                'title' => trim($group['title']),
                'artist_credit' => $credits,
                'primary_type' => is_string($group['primary-type'] ?? null) && trim($group['primary-type']) !== '' ? strtolower(trim($group['primary-type'])) : null,
                'secondary_types' => $secondary,
                'group_date' => $this->dateValues($group['first-release-date'] ?? null),
                'official_release_mbid' => strtolower($official['id']),
                'official_release_date' => $this->dateValues($official['date'] ?? null),
            ];
        }
        $candidates = collect($candidates)->sortBy(fn (array $candidate): string => implode(':', [
            $candidate['group_date']['sort'], mb_strtolower($candidate['title']), $candidate['mbid'],
        ]))->values()->all();

        $generation = DB::transaction(function () use ($artist, $artistMbid, $browse, $candidates): ArtistDiscographyGeneration {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["disco:artist-discography:{$artist->id}"]);
            $identity = ExternalIdentifier::query()->where('entity_id', $artist->id)->where('namespace', 'musicbrainz.artist')
                ->where('status', 'active')->lockForUpdate()->get();
            if ($artist->refresh()->status !== 'active' || $identity->count() !== 1 || strtolower($identity->first()->value) !== $artistMbid) {
                throw new RuntimeException('Artist identity changed during discography refresh.');
            }
            $now = now();
            $generation = ArtistDiscographyGeneration::query()->create([
                'artist_entity_id' => $artist->id,
                'artist_mbid' => $artistMbid,
                'source_total' => $browse['total'],
                'page_count' => $browse['pages'],
                'truncated' => $browse['truncated'],
                'algorithm_version' => self::ALGORITHM,
                'generated_at' => $now,
                'expires_at' => $now->copy()->addDays((int) config('discovery.discography.stale_after_days', 14)),
            ]);
            $seenGroups = [];
            $position = 0;
            foreach ($candidates as $candidate) {
                $group = $this->materializeReleaseGroup($candidate);
                if (isset($seenGroups[$group->id])) {
                    continue;
                }
                $seenGroups[$group->id] = true;
                ArtistDiscographyItem::query()->create([
                    'generation_id' => $generation->id,
                    'release_group_id' => $group->id,
                    'release_group_mbid' => $candidate['mbid'],
                    'title' => $candidate['title'],
                    'artist_credit' => $candidate['artist_credit'],
                    'primary_type' => $candidate['primary_type'],
                    'secondary_types' => $candidate['secondary_types'],
                    'first_release_year' => $candidate['group_date']['year'],
                    'first_release_month' => $candidate['group_date']['month'],
                    'first_release_day' => $candidate['group_date']['day'],
                    'date_precision' => $candidate['group_date']['precision'],
                    'official_release_mbid' => $candidate['official_release_mbid'],
                    'official_release_date' => $candidate['official_release_date']['date'],
                    'position' => ++$position,
                ]);
            }

            return $generation;
        });
        ArtistDiscographyGeneration::query()
            ->where('artist_entity_id', $artist->id)
            ->where('generated_at', '<', now()->subDays(90))
            ->delete();

        return [
            'generation_id' => $generation->id,
            'artist_id' => $artist->id,
            'items' => $generation->items()->count(),
            'source_total' => $browse['total'],
            'pages' => $browse['pages'],
            'truncated' => $browse['truncated'],
        ];
    }

    /** @return list<array{name:string,artist_mbid:string,artist_entity_id:string,joinphrase:string}>|null */
    private function validatedCredits(mixed $value, string $artistMbid): ?array
    {
        if (! is_array($value) || $value === [] || count($value) > 20) {
            throw new RuntimeException('MusicBrainz returned malformed artist credits.');
        }
        $credits = [];
        $containsArtist = false;
        foreach ($value as $credit) {
            $mbid = is_array($credit) ? data_get($credit, 'artist.id') : null;
            $name = is_array($credit) ? ($credit['name'] ?? data_get($credit, 'artist.name')) : null;
            if (! is_string($mbid) || ! Str::isUuid($mbid) || ! is_string($name) || trim($name) === '') {
                throw new RuntimeException('MusicBrainz returned malformed artist credits.');
            }
            $mbid = strtolower($mbid);
            $containsArtist = $containsArtist || $mbid === $artistMbid;
            $credits[] = [
                'name' => trim($name),
                'artist_mbid' => $mbid,
                'artist_entity_id' => '',
                'joinphrase' => is_string($credit['joinphrase'] ?? null) ? $credit['joinphrase'] : '',
            ];
        }

        return $containsArtist ? $credits : null;
    }

    /** @param array<string,mixed> $candidate */
    private function materializeReleaseGroup(array &$candidate): CatalogEntity
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["disco:release-group:{$candidate['mbid']}"]);
        $credits = collect($candidate['artist_credit'])->map(function (array $credit): array {
            $artist = $this->entityForIdentifier('musicbrainz.artist', $credit['artist_mbid'], 'agent', $credit['name']);
            Agent::query()->firstOrCreate(['entity_id' => $artist->id], ['agent_type' => 'other']);
            $credit['artist_entity_id'] = $artist->id;

            return $credit;
        })->all();
        $candidate['artist_credit'] = $credits;
        $entity = $this->entityForIdentifier('musicbrainz.release_group', $candidate['mbid'], 'release_group', $candidate['title']);
        $group = ReleaseGroup::query()->firstOrNew(['entity_id' => $entity->id]);
        $group->fill([
            'primary_type' => $group->primary_type ?? $candidate['primary_type'] ?? 'other',
            'secondary_types' => $group->secondary_types ?: $candidate['secondary_types'],
            'first_release_year' => $group->first_release_year ?? $candidate['group_date']['year'],
            'first_release_month' => $group->first_release_month ?? $candidate['group_date']['month'],
            'first_release_day' => $group->first_release_day ?? $candidate['group_date']['day'],
            'date_precision' => $group->date_precision ?? $candidate['group_date']['precision'],
        ])->save();
        $metadata = EntityMetadata::query()->find($entity->id);
        $attributes = $metadata?->attributes ?? [];
        $attributes['release_group_mbid'] ??= $candidate['mbid'];
        $attributes['basis_release_mbid'] ??= $candidate['official_release_mbid'];
        EntityMetadata::query()->updateOrCreate(['entity_id' => $entity->id], [
            'source_provider' => $metadata?->source_provider ?? 'musicbrainz',
            'genres' => $metadata?->genres ?? [],
            'primary_type' => $metadata?->primary_type ?? $candidate['primary_type'],
            'first_release_year' => $metadata?->first_release_year ?? $candidate['group_date']['year'],
            'first_release_month' => $metadata?->first_release_month ?? $candidate['group_date']['month'],
            'first_release_day' => $metadata?->first_release_day ?? $candidate['group_date']['day'],
            'first_release_precision' => $metadata?->first_release_precision ?? ($candidate['group_date']['precision'] === 'unknown' ? null : $candidate['group_date']['precision']),
            'artist_credit' => $metadata?->artist_credit ?: $credits,
            'external_links' => $metadata?->external_links ?? [],
            'attributes' => $attributes,
            'enriched_at' => now(),
        ]);

        $releaseEntity = $this->entityForIdentifier('musicbrainz.release', $candidate['official_release_mbid'], 'release', $candidate['title']);
        $existingRelease = Release::query()->find($releaseEntity->id);
        if ($existingRelease !== null && $existingRelease->release_group_id !== $entity->id) {
            $existingGroup = $this->canonicalEntities->resolve($existingRelease->release_group_id, 'release_group');
            if ($existingGroup?->id !== $entity->id) {
                throw new RuntimeException("Official release [{$candidate['official_release_mbid']}] conflicts with its exact release group.");
            }
        }
        $releaseDate = $candidate['official_release_date'];
        if ($releaseDate['precision'] === 'unknown' && $existingRelease !== null) {
            $releaseDate = [
                'year' => $existingRelease->release_year,
                'month' => $existingRelease->release_month,
                'day' => $existingRelease->release_day,
                'precision' => $existingRelease->date_precision,
            ];
        }
        Release::query()->updateOrCreate(['entity_id' => $releaseEntity->id], [
            'release_group_id' => $entity->id,
            'status' => 'official',
            'release_year' => $releaseDate['year'],
            'release_month' => $releaseDate['month'],
            'release_day' => $releaseDate['day'],
            'date_precision' => $releaseDate['precision'],
        ]);

        return $entity;
    }

    private function entityForIdentifier(string $namespace, string $value, string $kind, string $name): CatalogEntity
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["disco:identifier:{$namespace}:{$value}"]);
        $identifier = ExternalIdentifier::query()->where('namespace', $namespace)->where('value', $value)->lockForUpdate()->first();
        if ($identifier !== null && ! in_array($identifier->status, ['active', 'redirected'], true)) {
            throw new RuntimeException("Exact {$namespace} identity [{$value}] is inactive.");
        }
        $entity = $identifier?->entity;
        if ($entity?->status === 'redirected') {
            $entity = $this->canonicalEntities->resolve($entity->id, $kind);
            if ($entity !== null) {
                $identifier->update(['entity_id' => $entity->id]);
            }
        }
        if ($entity !== null && ($entity->kind !== $kind || $entity->status !== 'active')) {
            throw new RuntimeException("Exact {$namespace} identity [{$value}] conflicts with the canonical catalog.");
        }
        $entity ??= CatalogEntity::query()->create([
            'kind' => $kind,
            'status' => 'active',
            'canonical_name' => $name,
            'sort_name' => $name,
        ]);
        if ($identifier === null) {
            ExternalIdentifier::query()->create([
                'entity_id' => $entity->id,
                'namespace' => $namespace,
                'value' => $value,
                'status' => 'active',
            ]);
        }

        return $entity;
    }

    /** @return array{year:?int,month:?int,day:?int,precision:string,date:?string,sort:string} */
    private function dateValues(mixed $value): array
    {
        $parts = is_string($value) && preg_match('/\A(\d{4})(?:-(\d{2})(?:-(\d{2}))?)?\z/', $value, $matches) === 1 ? $matches : [];
        $year = isset($parts[1]) ? (int) $parts[1] : null;
        $month = isset($parts[2]) ? (int) $parts[2] : null;
        $day = isset($parts[3]) ? (int) $parts[3] : null;
        if (($month !== null && ($month < 1 || $month > 12)) || ($day !== null && ! checkdate($month ?? 1, $day, $year ?? 1))) {
            $year = $month = $day = null;
        }
        $precision = $day !== null ? 'day' : ($month !== null ? 'month' : ($year !== null ? 'year' : 'unknown'));
        $date = $day !== null ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;

        return [
            'year' => $year,
            'month' => $month,
            'day' => $day,
            'precision' => $precision,
            'date' => $date,
            'sort' => $year === null ? '9999-99-99' : sprintf('%04d-%02d-%02d', $year, $month ?? 99, $day ?? 99),
        ];
    }
}
