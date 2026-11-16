<?php

namespace App\Music\Discovery;

use App\Models\CatalogEntity;
use App\Models\ExternalIdentifier;
use App\Models\Release;
use App\Models\ReleaseGroup;
use App\Music\Artwork\CoverArtArchiveIngestor;
use App\Music\MusicBrainz\MusicBrainzClient;
use App\Music\QobuzDestinationResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DiscoveryAlbumResolver
{
    private array $groups = [];

    public function __construct(
        private readonly MusicBrainzClient $musicBrainz,
        private readonly AlbumEligibility $eligibility,
        private readonly QobuzDestinationResolver $qobuz,
        private readonly CoverArtArchiveIngestor $artwork,
    ) {}

    public function group(string $mbid, string $artistMbid): ?array
    {
        if (! Str::isUuid($mbid)) {
            return null;
        }
        $group = $this->groups[$mbid] ??= $this->musicBrainz->entity('release-group', $mbid);

        return $this->eligibility->allows($group, $artistMbid) ? $group : null;
    }

    // Called only by jobs. Full release lookups provide URL relationships missing on the group.
    public function prepare(array $group, string $artistMbid, string $artistName, ?string $caaReleaseMbid = null): array
    {
        $links = $this->links($group);
        $release = null;
        $releases = collect($group['releases'] ?? [])->filter(fn ($release) => Str::isUuid($release['id'] ?? null));
        $releaseMbid = $caaReleaseMbid ?? $releases->first()['id'] ?? null;
        if ($releaseMbid === null) {
            $releaseMbid = $this->musicBrainz->officialRelease($group['id'])['id'] ?? null;
        }
        if ($releaseMbid !== null) {
            $release = $this->musicBrainz->entity('release', $releaseMbid);
            if (strtolower((string) data_get($release, 'release-group.id')) !== strtolower($group['id'])) {
                throw new RuntimeException('Discovery release does not belong to its release group.');
            }
            $links = [...$links, ...$this->links($release)];
        }
        if ($this->qobuz->resolve('album', $links, $group['title'], $artistName)['status'] !== 'exact') {
            foreach ($releases->where('id', '!=', $releaseMbid)->take(3) as $alternate) {
                try {
                    $payload = $this->musicBrainz->entity('release', $alternate['id']);
                    if (strtolower((string) data_get($payload, 'release-group.id')) !== strtolower($group['id'])) {
                        continue;
                    }
                    $links = [...$links, ...$this->links($payload)];
                    if ($this->qobuz->resolve('album', $links, $group['title'], $artistName)['status'] === 'exact') {
                        break;
                    }
                } catch (Throwable $exception) {
                    Log::warning('Discovery release links unavailable.', ['error_code' => class_basename($exception)]);
                }
            }
        }
        $entity = DB::transaction(function () use ($group, $release, $releaseMbid): CatalogEntity {
            $entity = $this->entity('release_group', 'musicbrainz.release_group', $group['id'], $group['title']);
            ReleaseGroup::query()->updateOrCreate(['entity_id' => $entity->id], [
                'primary_type' => strtolower($group['primary-type']),
                'secondary_types' => $group['secondary-types'] ?? [],
                'first_release_year' => preg_match('/^\d{4}/', $group['first-release-date'] ?? '') ? (int) substr($group['first-release-date'], 0, 4) : null,
            ]);
            if ($release !== null) {
                $releaseEntity = $this->entity('release', 'musicbrainz.release', $releaseMbid, $release['title'] ?? $group['title']);
                $existing = Release::query()->find($releaseEntity->id);
                if ($existing !== null && $existing->release_group_id !== $entity->id) {
                    throw new RuntimeException('Discovery release identity conflicts with the catalog.');
                }
                Release::query()->firstOrCreate(['entity_id' => $releaseEntity->id], [
                    'release_group_id' => $entity->id, 'status' => strtolower($release['status'] ?? 'official'),
                ]);
            }

            return $entity;
        });
        if ($releaseMbid !== null) {
            try {
                $this->artwork->ingest($entity, $releaseMbid);
            } catch (Throwable $exception) {
                Log::warning('Discovery artwork unavailable.', ['entity_id' => $entity->id, 'error_code' => class_basename($exception)]);
            }
        }
        $destination = $this->qobuz->resolve('album', $links, $group['title'], $artistName);

        return [
            'entity_id' => $entity->id, 'artist_mbid' => $artistMbid, 'artist_name' => $artistName,
            'release_group_mbid' => $group['id'], 'title' => $group['title'],
            'release_type' => strtolower($group['primary-type']) === 'ep' ? 'EP' : 'Album',
            'release_date' => ($group['first-release-date'] ?? '') ?: null,
            'qobuz_url' => $destination['url'], 'qobuz_status' => $destination['status'],
        ];
    }

    private function links(array $entity): array
    {
        return collect($entity['relations'] ?? [])->filter(fn ($relation) => ($relation['target-type'] ?? null) === 'url')
            ->map(fn ($relation) => ['url' => data_get($relation, 'url.resource')])->all();
    }

    private function entity(string $kind, string $namespace, string $mbid, string $title): CatalogEntity
    {
        $identifier = ExternalIdentifier::query()->where('namespace', $namespace)->where('value', $mbid)->first();
        if ($identifier !== null) {
            if ($identifier->status !== 'active' || $identifier->entity?->status !== 'active') {
                throw new RuntimeException('Discovery identity is inactive.');
            }

            return $identifier->entity;
        }
        $entity = CatalogEntity::query()->create(['kind' => $kind, 'status' => 'active', 'canonical_name' => $title, 'sort_name' => $title]);
        ExternalIdentifier::query()->create(['entity_id' => $entity->id, 'namespace' => $namespace, 'value' => $mbid, 'status' => 'active']);

        return $entity;
    }
}
