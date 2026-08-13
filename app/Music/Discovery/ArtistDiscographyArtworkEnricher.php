<?php

namespace App\Music\Discovery;

use App\Models\ArtistDiscographyGeneration;
use App\Models\ArtistDiscographyItem;
use App\Music\Artwork\CoverArtArchiveIngestor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ArtistDiscographyArtworkEnricher
{
    public function __construct(private readonly CoverArtArchiveIngestor $artwork) {}

    /** @return array{requested:int,ready:int,missing:int,failed:int} */
    public function enrich(int $limit = 10, ?string $artistId = null, bool $refresh = false): array
    {
        if ($limit < 1 || $limit > 50 || ($artistId !== null && ! Str::isUuid($artistId))) {
            throw new RuntimeException('Invalid artist discography artwork request.');
        }

        $generationIds = ArtistDiscographyGeneration::query()
            ->when($artistId !== null, fn (Builder $query) => $query->where('artist_entity_id', $artistId))
            ->orderByDesc('generated_at')
            ->get(['id', 'artist_entity_id'])
            ->unique('artist_entity_id')
            ->pluck('id');
        if ($generationIds->isEmpty()) {
            return ['requested' => 0, 'ready' => 0, 'missing' => 0, 'failed' => 0];
        }

        $missingDue = now()->subDays((int) config('services.cover_art_archive.missing_ttl_days', 30));
        $retryDue = now()->subHours((int) config('services.cover_art_archive.retry_ttl_hours', 24));
        $items = ArtistDiscographyItem::query()
            ->whereIn('generation_id', $generationIds)
            ->whereHas('releaseGroup', fn (Builder $query) => $query->where('status', 'active')->where('kind', 'release_group'))
            ->when(! $refresh, fn (Builder $query) => $query->where(function (Builder $due) use ($missingDue, $retryDue): void {
                $due->whereDoesntHave('releaseGroup.artwork')
                    ->orWhereHas('releaseGroup.artwork', fn (Builder $artwork) => $artwork
                        ->where('status', 'pending')
                        ->orWhere(fn (Builder $retry) => $retry->whereIn('status', ['failed', 'stale'])->where('last_attempt_at', '<=', $retryDue))
                        ->orWhere(fn (Builder $missing) => $missing->where('status', 'missing')->where('last_attempt_at', '<=', $missingDue)));
            }))
            ->with('releaseGroup.artwork')
            ->orderBy('position')
            ->orderBy('generation_id')
            ->limit($limit)
            ->get();

        $counts = ['requested' => 0, 'ready' => 0, 'missing' => 0, 'failed' => 0];
        foreach ($items as $item) {
            $counts['requested']++;
            try {
                $result = $this->artwork->ingest($item->releaseGroup, strtolower($item->official_release_mbid), $refresh);
                if (in_array($result->status, ['ready', 'stale'], true)) {
                    $counts['ready']++;
                } elseif ($result->status === 'missing') {
                    $counts['missing']++;
                } else {
                    $counts['failed']++;
                }
            } catch (Throwable $exception) {
                $counts['failed']++;
                Log::warning('Artist discography artwork enrichment failed.', [
                    'release_group_id' => $item->release_group_id,
                    'error_code' => class_basename($exception),
                ]);
            }
        }

        return $counts;
    }
}
