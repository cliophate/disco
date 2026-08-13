<?php

namespace App\Music\Discovery;

use App\Models\ExternalIdentifier;
use App\Models\RecommendationRun;
use App\Music\Artwork\CoverArtArchiveIngestor;
use App\Music\MusicBrainz\MusicBrainzClient;
use App\Music\MusicBrainz\MusicBrainzTracklistProjector;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class BeyondLibraryMetadataEnricher
{
    public function __construct(
        private readonly MusicBrainzClient $musicBrainz,
        private readonly MusicBrainzTracklistProjector $tracklists,
        private readonly CoverArtArchiveIngestor $artwork,
    ) {}

    /** @return array{requested:int,tracklists:int,artworks:int,missing_artworks:int,failed:int} */
    public function enrich(int $limit = 20, bool $refresh = false, bool $retryArtwork = false): array
    {
        if ($limit < 1 || $limit > 50) {
            throw new RuntimeException('Invalid Beyond metadata enrichment limit.');
        }
        $run = RecommendationRun::query()
            ->where('intent', 'beyond_library')
            ->where('status', 'completed')
            ->whereHas('items')
            ->latest('generated_at')
            ->with(['items.entity.metadata', 'items.entity.artwork', 'items.entity.releaseGroup.releases'])
            ->first();
        $counts = ['requested' => 0, 'tracklists' => 0, 'artworks' => 0, 'missing_artworks' => 0, 'failed' => 0];
        if ($run === null) {
            return $counts;
        }
        foreach ($run->items->take($limit) as $item) {
            $counts['requested']++;
            try {
                $group = $item->entity;
                $releaseMbid = strtolower((string) ($group->metadata?->attributes['basis_release_mbid'] ?? ''));
                $identifier = ExternalIdentifier::query()
                    ->where('namespace', 'musicbrainz.release')
                    ->where('value', $releaseMbid)
                    ->where('status', 'active')
                    ->with('entity.release')
                    ->first();
                $release = $identifier?->entity?->release;
                if ($release === null || $release->release_group_id !== $group->id) {
                    throw new RuntimeException('Beyond album has no valid basis release.');
                }
                if ($refresh || ! $release->media()->exists()) {
                    $payload = $this->musicBrainz->entity('release', $releaseMbid);
                    if (strtolower((string) data_get($payload, 'release-group.id')) !== strtolower((string) data_get($group->metadata?->attributes, 'release_group_mbid'))) {
                        throw new RuntimeException('MusicBrainz basis release changed release groups.');
                    }
                    $this->tracklists->project($release, $payload);
                    $counts['tracklists']++;
                }
                if ($refresh || $group->artwork?->status !== 'ready') {
                    $artwork = $this->artwork->ingest($group, $releaseMbid, $refresh || $retryArtwork);
                    match ($artwork->status) {
                        'ready', 'stale' => $counts['artworks']++,
                        'missing' => $counts['missing_artworks']++,
                        default => $counts['failed']++,
                    };
                }
            } catch (Throwable $exception) {
                $counts['failed']++;
                Log::warning('Beyond album metadata enrichment failed.', [
                    'entity_id' => $item->entity_id,
                    'error_code' => class_basename($exception),
                ]);
            }
        }

        return $counts;
    }

    /** @return array<string, int> */
    public function coverage(int $limit = 50): array
    {
        if ($limit < 1 || $limit > 50) {
            throw new RuntimeException('Invalid Beyond metadata enrichment limit.');
        }
        $run = RecommendationRun::query()->where('intent', 'beyond_library')->where('status', 'completed')
            ->whereHas('items')->latest('generated_at')->with('items.entity.artwork')->first();
        $counts = ['eligible' => 0, 'ready' => 0, 'ready_invalid' => 0, 'missing_due' => 0, 'missing_deferred' => 0, 'failed_due' => 0, 'failed_deferred' => 0, 'stale_due' => 0, 'stale_deferred' => 0, 'unattempted' => 0];
        foreach ($run?->items->take($limit) ?? [] as $item) {
            $counts['eligible']++;
            $artwork = $item->entity?->artwork;
            if ($artwork === null || $artwork->status === 'pending') {
                $counts['unattempted']++;
            } elseif ($artwork->status === 'ready') {
                $valid = $artwork->storage_key !== null && $artwork->content_sha256 !== null
                    && Storage::disk('artwork')->exists($artwork->storage_key)
                    && hash_equals($artwork->content_sha256, hash('sha256', Storage::disk('artwork')->get($artwork->storage_key)));
                $counts[$valid ? 'ready' : 'ready_invalid']++;
            } else {
                $ttl = $artwork->status === 'missing' ? now()->subDays((int) config('services.cover_art_archive.missing_ttl_days', 30)) : now()->subHours((int) config('services.cover_art_archive.retry_ttl_hours', 24));
                $counts[$artwork->status.'_'.($artwork->last_attempt_at?->isAfter($ttl) ? 'deferred' : 'due')]++;
            }
        }

        return $counts;
    }
}
