<?php

namespace App\Music\Discovery;

use App\Models\Agent;
use App\Models\CatalogEntity;
use App\Models\EntityMetadata;
use App\Models\ExternalIdentifier;
use App\Models\Release;
use App\Models\ReleaseGroup;
use App\Music\MusicBrainz\MusicBrainzClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ExternalCatalogService
{
    private const EXCLUDED_SECONDARY_TYPES = ['broadcast', 'compilation', 'dj-mix', 'mixtape/street'];

    public function __construct(private readonly MusicBrainzClient $client) {}

    /** @return list<array<string, mixed>> */
    public function search(string $query): array
    {
        $results = collect($this->client->searchReleaseGroups($query, 20))
            ->filter(fn (array $result): bool => $this->isEligible($result))
            ->take(12)
            ->values();
        $mbids = $results->pluck('id')->filter(fn ($id): bool => is_string($id) && Str::isUuid($id))->map('strtolower');
        $entities = ExternalIdentifier::query()
            ->where('namespace', 'musicbrainz.release_group')
            ->whereIn('value', $mbids)
            ->whereIn('status', ['active', 'redirected'])
            ->with(['entity.artwork', 'entity.releaseGroup.holdings.plexAlbum'])
            ->get()
            ->keyBy(fn (ExternalIdentifier $identifier): string => strtolower($identifier->value));

        return $results->map(function (array $result) use ($entities): array {
            $mbid = strtolower($result['id']);
            $identifier = $entities->get($mbid);
            $entity = $identifier?->entity;
            if ($entity?->status === 'redirected' && $entity->redirect_entity_id !== null) {
                $entity = CatalogEntity::query()->with(['artwork', 'releaseGroup.holdings.plexAlbum'])->find($entity->redirect_entity_id);
            }
            $artist = collect($result['artist-credit'] ?? [])->map(fn ($credit): string => trim((string) ($credit['name'] ?? data_get($credit, 'artist.name', '')).(string) ($credit['joinphrase'] ?? '')))->implode('');

            return [
                'mbid' => $mbid,
                'title' => trim((string) $result['title']),
                'artist' => $artist === '' ? 'Unknown artist' : $artist,
                'first_release_date' => is_string($result['first-release-date'] ?? null) ? $result['first-release-date'] : null,
                'primary_type' => $result['primary-type'],
                'disambiguation' => is_string($result['disambiguation'] ?? null) && trim($result['disambiguation']) !== '' ? trim($result['disambiguation']) : null,
                'artwork_status' => $entity?->artwork?->status ?? 'unknown',
                'entity_id' => $entity?->id,
                'owned' => $entity?->releaseGroup?->holdings?->contains(fn ($holding): bool => $holding->plexAlbum?->removed_at === null) ?? false,
            ];
        })->all();
    }

    /** @return array{entity:CatalogEntity,release_mbid:?string} */
    public function materialize(string $mbid): array
    {
        if (! Str::isUuid($mbid)) {
            throw new RuntimeException('Invalid MusicBrainz release-group selection.');
        }
        $mbid = strtolower($mbid);
        $payload = $this->client->entity('release-group', $mbid);
        if (! $this->isEligible($payload)) {
            throw new RuntimeException('The selected MusicBrainz result is not an album or EP supported by external search.');
        }

        return DB::transaction(function () use ($mbid, $payload): array {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["disco:external-release-group:{$mbid}"]);
            $credits = $this->materializeCredits($payload['artist-credit'] ?? []);
            $date = $this->dateValues($payload['first-release-date'] ?? null);
            $secondaryTypes = is_array($payload['secondary-types'] ?? null) ? $payload['secondary-types'] : [];
            $entity = $this->entityForIdentifier('musicbrainz.release_group', $mbid, 'release_group', (string) $payload['title']);
            ReleaseGroup::query()->updateOrCreate(
                ['entity_id' => $entity->id],
                [
                    'primary_type' => strtolower((string) $payload['primary-type']),
                    'secondary_types' => array_values(array_filter($secondaryTypes, 'is_string')),
                    ...$date,
                ],
            );
            $release = collect($payload['releases'] ?? [])->first(fn ($release): bool => is_array($release) && is_string($release['id'] ?? null) && Str::isUuid($release['id']));
            $releaseMbid = is_array($release) ? strtolower($release['id']) : null;
            if ($releaseMbid !== null) {
                $releaseEntity = $this->entityForIdentifier('musicbrainz.release', $releaseMbid, 'release', (string) ($release['title'] ?? $payload['title']));
                Release::query()->updateOrCreate(
                    ['entity_id' => $releaseEntity->id],
                    [
                        'release_group_id' => $entity->id,
                        'status' => strtolower((string) ($release['status'] ?? 'unknown')),
                        ...$this->releaseDateValues($release['date'] ?? null),
                    ],
                );
            }
            EntityMetadata::query()->updateOrCreate(
                ['entity_id' => $entity->id],
                [
                    'source_provider' => 'musicbrainz',
                    'primary_type' => $payload['primary-type'],
                    'first_release_year' => $date['first_release_year'],
                    'first_release_month' => $date['first_release_month'],
                    'first_release_day' => $date['first_release_day'],
                    'first_release_precision' => $date['date_precision'] === 'unknown' ? null : $date['date_precision'],
                    'disambiguation' => is_string($payload['disambiguation'] ?? null) ? $payload['disambiguation'] : null,
                    'artist_credit' => $credits,
                    'attributes' => ['basis_release_mbid' => $releaseMbid, 'release_group_mbid' => $mbid],
                    'enriched_at' => now(),
                ],
            );

            return ['entity' => $entity->refresh(), 'release_mbid' => $releaseMbid];
        });
    }

    private function isEligible(array $result): bool
    {
        if (! is_string($result['id'] ?? null) || ! Str::isUuid($result['id']) || ! is_string($result['title'] ?? null) || trim($result['title']) === '') {
            return false;
        }
        if (! in_array(strtolower((string) ($result['primary-type'] ?? '')), ['album', 'ep'], true)) {
            return false;
        }
        $secondaryTypes = is_array($result['secondary-types'] ?? null) ? $result['secondary-types'] : [];
        $secondary = collect($secondaryTypes)->filter(fn ($type): bool => is_string($type))->map(fn (string $type): string => strtolower($type));

        return $secondary->intersect(self::EXCLUDED_SECONDARY_TYPES)->isEmpty();
    }

    /** @return list<array{name:string,artist_mbid:string,artist_entity_id:string,joinphrase:string}> */
    private function materializeCredits(mixed $credits): array
    {
        if (! is_array($credits) || count($credits) < 1 || count($credits) > 20) {
            throw new RuntimeException('The selected release group has invalid artist credits.');
        }

        return collect($credits)->map(function ($credit): array {
            $name = is_array($credit) ? ($credit['name'] ?? data_get($credit, 'artist.name')) : null;
            $mbid = is_array($credit) ? data_get($credit, 'artist.id') : null;
            if (! is_string($name) || trim($name) === '' || ! is_string($mbid) || ! Str::isUuid($mbid)) {
                throw new RuntimeException('The selected release group has incomplete artist credits.');
            }
            $entity = $this->entityForIdentifier('musicbrainz.artist', strtolower($mbid), 'agent', trim($name));
            Agent::query()->updateOrCreate(['entity_id' => $entity->id], ['agent_type' => 'other']);

            return [
                'name' => trim($name),
                'artist_mbid' => strtolower($mbid),
                'artist_entity_id' => $entity->id,
                'joinphrase' => is_string($credit['joinphrase'] ?? null) ? $credit['joinphrase'] : '',
            ];
        })->values()->all();
    }

    private function entityForIdentifier(string $namespace, string $value, string $kind, string $name): CatalogEntity
    {
        $identifier = ExternalIdentifier::query()->where('namespace', $namespace)->where('value', $value)->first();
        $entity = $identifier?->entity;
        for ($redirects = 0; $entity?->status === 'redirected' && $entity->redirect_entity_id !== null && $redirects < 5; $redirects++) {
            $entity = CatalogEntity::query()->find($entity->redirect_entity_id);
        }
        if ($entity !== null && $entity->kind !== $kind) {
            throw new RuntimeException('The selected MusicBrainz identity conflicts with the canonical catalog.');
        }
        $entity ??= CatalogEntity::query()->create([
            'kind' => $kind,
            'status' => 'active',
            'canonical_name' => $name,
            'sort_name' => $name,
        ]);
        ExternalIdentifier::query()->updateOrCreate(
            ['namespace' => $namespace, 'value' => $value],
            ['entity_id' => $entity->id, 'status' => 'active'],
        );

        return $entity;
    }

    /** @return array{first_release_year:?int,first_release_month:?int,first_release_day:?int,date_precision:string} */
    private function dateValues(mixed $date): array
    {
        $parts = is_string($date) && preg_match('/\A(\d{4})(?:-(\d{2})(?:-(\d{2}))?)?\z/', $date, $matches) === 1 ? $matches : [];

        return [
            'first_release_year' => isset($parts[1]) ? (int) $parts[1] : null,
            'first_release_month' => isset($parts[2]) ? (int) $parts[2] : null,
            'first_release_day' => isset($parts[3]) ? (int) $parts[3] : null,
            'date_precision' => isset($parts[3]) ? 'day' : (isset($parts[2]) ? 'month' : (isset($parts[1]) ? 'year' : 'unknown')),
        ];
    }

    /** @return array{release_year:?int,release_month:?int,release_day:?int,date_precision:string} */
    private function releaseDateValues(mixed $date): array
    {
        $values = $this->dateValues($date);

        return [
            'release_year' => $values['first_release_year'],
            'release_month' => $values['first_release_month'],
            'release_day' => $values['first_release_day'],
            'date_precision' => $values['date_precision'],
        ];
    }
}
