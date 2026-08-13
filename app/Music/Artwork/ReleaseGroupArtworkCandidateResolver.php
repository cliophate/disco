<?php

namespace App\Music\Artwork;

use App\Models\CatalogEntity;
use App\Models\ExternalIdentifier;
use App\Music\MusicBrainz\MusicBrainzClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class ReleaseGroupArtworkCandidateResolver
{
    public function __construct(private readonly MusicBrainzClient $musicBrainz) {}

    /** @return list<string> */
    public function candidates(CatalogEntity $entity, string $basisReleaseMbid, bool $refresh = false): array
    {
        $basisReleaseMbid = strtolower($basisReleaseMbid);
        $groupMbid = $this->assertBasis($entity, $basisReleaseMbid);
        $cacheKey = "disco:artwork-release-candidates:{$groupMbid}";
        if ($refresh) {
            Cache::forget($cacheKey);
        }
        $releases = Cache::remember($cacheKey, now()->addDay(), function () use ($groupMbid): array {
            $releases = $this->musicBrainz->releaseGroupReleases($groupMbid);

            return collect($releases)->filter(fn ($release): bool => is_array($release)
                && Str::isUuid($release['id'] ?? null)
                && strcasecmp((string) ($release['status'] ?? ''), 'Official') === 0)
                ->map(fn (array $release): array => [
                    'id' => strtolower($release['id']),
                    'front' => data_get($release, 'cover-art-archive.front') === true,
                    'date' => is_string($release['date'] ?? null) ? $release['date'] : '9999-99-99',
                ])->unique('id')->values()->all();
        });
        $alternates = collect($releases)->reject(fn (array $release): bool => $release['id'] === $basisReleaseMbid)
            ->sortBy(fn (array $release): string => implode(':', [$release['front'] ? '0' : '1', $release['date'], $release['id']]))
            ->pluck('id');

        return collect([$basisReleaseMbid])->concat($alternates)
            ->take((int) config('services.cover_art_archive.release_attempt_limit', 5))->values()->all();
    }

    public function assertBasis(CatalogEntity $entity, string $basisReleaseMbid): string
    {
        $basisReleaseMbid = strtolower($basisReleaseMbid);
        $basisGroupId = ExternalIdentifier::query()->where('namespace', 'musicbrainz.release')->where('value', $basisReleaseMbid)
            ->where('status', 'active')->with('entity.release')->first()?->entity?->release?->release_group_id;
        if ($basisGroupId !== $entity->id) {
            throw new RuntimeException('Cover artwork basis release does not belong to the requested album.');
        }
        $groupMbid = ExternalIdentifier::query()->where('entity_id', $entity->id)->where('namespace', 'musicbrainz.release_group')
            ->where('status', 'active')->value('value');
        if (! is_string($groupMbid) || ! Str::isUuid($groupMbid)) {
            throw new RuntimeException('Cover artwork requires an exact MusicBrainz release-group identity.');
        }

        return strtolower($groupMbid);
    }
}
