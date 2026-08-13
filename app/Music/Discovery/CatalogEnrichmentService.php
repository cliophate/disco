<?php

namespace App\Music\Discovery;

use App\Models\ArtistDiscographyGeneration;
use App\Models\ArtistDiscographyItem;
use App\Models\CatalogEntity;
use App\Models\CatalogEntityArtwork;
use App\Models\ExternalIdentifier;
use App\Models\RecommendationRun;
use App\Models\Release;
use App\Models\UpcomingReleaseGeneration;
use App\Models\User;
use App\Music\Artwork\CoverArtArchiveIngestor;
use App\Music\MusicBrainz\MusicBrainzEnricher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CatalogEnrichmentService
{
    public function __construct(
        private readonly MusicBrainzEnricher $musicBrainz,
        private readonly CoverArtArchiveIngestor $artwork,
        private readonly ArtistSeedService $artistSeeds,
    ) {}

    /** @return array{eligible:int,requested:int,detail:int,artwork:int,missing:int,failed:int,remaining_due:int} */
    public function enrich(int $limit = 50, bool $refresh = false, bool $retryArtwork = false): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new RuntimeException('Invalid catalog enrichment limit.');
        }

        $candidates = $this->candidates();
        $normal = $candidates->filter(fn (array $candidate): bool => $this->detailDue($candidate, $refresh)
            || $this->artworkDue($candidate, $refresh, false));
        $retry = $retryArtwork
            ? $candidates->diffKeys($normal)->filter(fn (array $candidate): bool => $this->artworkDue($candidate, false, true))
            : collect();
        $due = $normal->concat($retry);
        $selected = $due->take($limit)->values();
        $counts = [
            'eligible' => $candidates->count(),
            'requested' => $selected->count(),
            'detail' => 0,
            'artwork' => 0,
            'missing' => 0,
            'failed' => 0,
            'remaining_due' => max(0, $due->count() - $selected->count()),
        ];
        unset($candidates, $normal, $retry, $due);
        gc_collect_cycles();

        foreach ($selected as $candidate) {
            if ($this->detailDue($candidate, $refresh)) {
                try {
                    $this->musicBrainz->enrich($candidate['identifier']);
                    $release = $candidate['identifier']->entity->release()->with('media.tracks')->firstOrFail();
                    $release->media->isEmpty()
                        ? Cache::put($this->detailFailureKey($candidate), true, now()->addDays(7))
                        : Cache::forget($this->detailFailureKey($candidate));
                    $counts['detail']++;
                } catch (Throwable $exception) {
                    Cache::put($this->detailFailureKey($candidate), true, now()->addDay());
                    $counts['failed']++;
                    $this->logFailure($candidate, 'detail', $exception);
                }
            }

            if ($this->artworkDue($candidate, $refresh, $retryArtwork)) {
                try {
                    $artwork = $this->artwork->ingest(
                        $candidate['group'],
                        $candidate['release_mbid'],
                        $refresh || $retryArtwork,
                    );
                    Cache::forget($this->artworkFailureKey($candidate));
                    if (in_array($artwork->status, ['ready', 'stale'], true)) {
                        $counts['artwork']++;
                    } elseif ($artwork->status === 'missing') {
                        $counts['missing']++;
                    } else {
                        $counts['failed']++;
                    }
                } catch (Throwable $exception) {
                    Cache::put($this->artworkFailureKey($candidate), true, now()->addDay());
                    $counts['failed']++;
                    $this->logFailure($candidate, 'artwork', $exception);
                }
            }
        }

        return $counts;
    }

    /** @return array{eligible:int,detail_due:int,artwork_due:int,remaining_due:int,ready_artwork:int,last_activity:?string} */
    public function coverage(): array
    {
        $candidates = $this->candidates();
        $detailDue = $candidates->filter(fn (array $candidate): bool => $this->detailDue($candidate, false))->count();
        $artworkDue = $candidates->filter(fn (array $candidate): bool => $this->artworkDue($candidate, false, false))->count();
        $readyArtwork = $candidates->filter(fn (array $candidate): bool => $candidate['group']->artwork?->status === 'ready'
            && $this->validArtwork($candidate['group']->artwork))->count();
        $lastActivity = $candidates->pluck('group.artwork.last_attempt_at')->filter()->max();

        return [
            'eligible' => $candidates->count(),
            'detail_due' => $detailDue,
            'artwork_due' => $artworkDue,
            'remaining_due' => $candidates->filter(fn (array $candidate): bool => $this->detailDue($candidate, false)
                || $this->artworkDue($candidate, false, false))->count(),
            'ready_artwork' => $readyArtwork,
            'last_activity' => $lastActivity?->toAtomString(),
        ];
    }

    /** @return Collection<int, array{group:CatalogEntity,identifier:ExternalIdentifier,release_mbid:string,source:string,tracklist_missing:bool}> */
    private function candidates(): Collection
    {
        $upcomingRows = collect();
        $upcoming = UpcomingReleaseGeneration::query()->latest('generated_at')->first();
        if ($upcoming !== null) {
            $ownerId = User::query()->where('is_owner', true)->value('id');
            $seedMbids = is_string($ownerId) ? array_keys($this->artistSeeds->exactMbidStates($ownerId)) : [];
            $upcomingRows = $upcoming->items()->orderBy('general_rank')->get()
                ->sortBy(fn ($item): array => [array_intersect($item->artist_mbids, $seedMbids) === [] ? 1 : 0, $item->general_rank])
                ->values()->map(fn ($item): array => [
                    'group_id' => $item->release_group_id,
                    'release_mbid' => strtolower($item->release_mbid),
                    'source' => 'upcoming',
                ]);
        }

        $beyondRows = collect();
        $beyond = RecommendationRun::query()
            ->where('intent', 'beyond_library')
            ->where('status', 'completed')
            ->whereHas('items')
            ->latest('generated_at')
            ->with('items.entity.metadata')
            ->first();
        if ($beyond !== null) {
            $beyondRows = $beyond->items->map(fn ($item): array => [
                'group_id' => $item->entity_id,
                'release_mbid' => strtolower((string) ($item->entity?->metadata?->attributes['basis_release_mbid'] ?? '')),
                'source' => 'beyond',
            ]);
        }

        $discographyRows = collect();
        $generationIds = ArtistDiscographyGeneration::query()
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->get(['id', 'artist_entity_id'])
            ->unique('artist_entity_id')
            ->pluck('id');
        if ($generationIds->isNotEmpty()) {
            $discographyRows = ArtistDiscographyItem::query()
                ->whereIn('generation_id', $generationIds)
                ->orderBy('position')
                ->orderBy('generation_id')
                ->get()
                ->map(fn ($item): array => [
                    'group_id' => $item->release_group_id,
                    'release_mbid' => strtolower($item->official_release_mbid),
                    'source' => 'discography',
                ]);
        }
        $raw = $upcomingRows->take(48)
            ->concat($beyondRows)
            ->concat($discographyRows->take(50))
            ->concat($upcomingRows->skip(48))
            ->concat($discographyRows->skip(50));

        $releaseMbids = $raw->pluck('release_mbid')->filter(fn (string $mbid): bool => Str::isUuid($mbid))->unique();
        $identifiers = ExternalIdentifier::query()
            ->where('namespace', 'musicbrainz.release')
            ->where('status', 'active')
            ->whereIn('value', $releaseMbids)
            ->with(['entity.release'])
            ->get()
            ->keyBy(fn (ExternalIdentifier $identifier): string => strtolower($identifier->value));
        $missingTracklists = Release::query()
            ->whereIn('entity_id', $identifiers->pluck('entity_id'))
            ->where(fn ($query) => $query
                ->whereDoesntHave('media')
                ->orWhereHas('media', fn ($media) => $media->whereDoesntHave('tracks')))
            ->pluck('entity_id')
            ->flip();
        $groups = CatalogEntity::query()
            ->whereIn('id', $raw->pluck('group_id')->unique())
            ->where('kind', 'release_group')
            ->where('status', 'active')
            ->with(['releaseGroup', 'metadata', 'artwork'])
            ->get()
            ->keyBy('id');
        $seen = [];

        return $raw->map(function (array $candidate) use ($groups, $identifiers, $missingTracklists, &$seen): ?array {
            $group = $groups->get($candidate['group_id']);
            $identifier = $identifiers->get($candidate['release_mbid']);
            $release = $identifier?->entity?->release;
            if ($group === null || $group->releaseGroup === null || $identifier?->entity?->kind !== 'release'
                || $identifier->entity->status !== 'active' || $release === null
                || $release->release_group_id !== $group->id || isset($seen[$group->id])) {
                return null;
            }
            $seen[$group->id] = true;

            return [
                'group' => $group,
                'identifier' => $identifier,
                'release_mbid' => $candidate['release_mbid'],
                'source' => $candidate['source'],
                'tracklist_missing' => $missingTracklists->has($identifier->entity_id),
            ];
        })->filter()->values();
    }

    /** @param array{group:CatalogEntity,identifier:ExternalIdentifier,release_mbid:string,source:string,tracklist_missing:bool} $candidate */
    private function detailDue(array $candidate, bool $refresh): bool
    {
        if (! $refresh && Cache::has($this->detailFailureKey($candidate))) {
            return false;
        }
        $metadata = $candidate['group']->metadata;

        return $refresh || $candidate['tracklist_missing'] || $metadata === null
            || $metadata->enriched_at === null || $metadata->enriched_at->lt(now()->subDays(30));
    }

    /** @param array{group:CatalogEntity,identifier:ExternalIdentifier,release_mbid:string,source:string,tracklist_missing:bool} $candidate */
    private function detailFailureKey(array $candidate): string
    {
        return "disco:catalog-detail-failure:{$candidate['identifier']->id}";
    }

    /** @param array{group:CatalogEntity,identifier:ExternalIdentifier,release_mbid:string,source:string,tracklist_missing:bool} $candidate */
    private function artworkDue(array $candidate, bool $refresh, bool $retry): bool
    {
        $artwork = $candidate['group']->artwork;
        if (! $refresh && ! $retry && Cache::has($this->artworkFailureKey($candidate))) {
            return false;
        }
        if ($refresh || $artwork === null || $artwork->status === 'pending') {
            return true;
        }
        if ($artwork->status === 'ready') {
            return ! $this->validArtwork($artwork);
        }
        if ($retry) {
            return true;
        }

        $dueSince = $artwork->status === 'missing'
            ? now()->subDays((int) config('services.cover_art_archive.missing_ttl_days', 30))
            : now()->subHours((int) config('services.cover_art_archive.retry_ttl_hours', 24));

        return $artwork->last_attempt_at === null || $artwork->last_attempt_at->lte($dueSince);
    }

    /** @param array{group:CatalogEntity,identifier:ExternalIdentifier,release_mbid:string,source:string,tracklist_missing:bool} $candidate */
    private function artworkFailureKey(array $candidate): string
    {
        return "disco:catalog-artwork-failure:{$candidate['group']->id}";
    }

    private function validArtwork(CatalogEntityArtwork $artwork): bool
    {
        try {
            return $artwork->storage_key !== null && $artwork->content_sha256 !== null
                && Storage::disk('artwork')->exists($artwork->storage_key);
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array{group:CatalogEntity,identifier:ExternalIdentifier,release_mbid:string,source:string,tracklist_missing:bool} $candidate */
    private function logFailure(array $candidate, string $component, Throwable $exception): void
    {
        Log::warning('Current-surface catalog enrichment failed.', [
            'release_group_id' => $candidate['group']->id,
            'source' => $candidate['source'],
            'component' => $component,
            'error_code' => class_basename($exception),
        ]);
    }
}
