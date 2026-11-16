<?php

namespace App\Music\Discovery;

use App\Models\CatalogEntity;
use App\Models\DiscoveryPick;
use App\Music\Artwork\CoverArtArchiveIngestor;
use Illuminate\Support\Facades\Log;
use Throwable;

class DiscoveryArtworkRefresher
{
    public function __construct(private readonly CoverArtArchiveIngestor $artwork) {}

    public function refresh(int $limit = 50): int
    {
        $entityIds = DiscoveryPick::query()->whereNull('passed_at')->whereNotNull('entity_id')->pluck('entity_id')->unique();
        $entities = CatalogEntity::query()->whereIn('id', $entityIds)->where('status', 'active')
            ->whereDoesntHave('artwork', fn ($query) => $query->where('status', 'ready'))
            ->with(['artwork', 'releaseGroup.releases.entity.identifiers'])->orderBy('id')->get();
        $updated = 0;
        $attempted = 0;
        foreach ($entities as $entity) {
            $releasedSinceAttempt = DiscoveryPick::query()->where('entity_id', $entity->id)
                ->whereNotNull('release_date')->where('release_date', '<=', now()->toDateString())
                ->where('release_date', '>', $entity->artwork?->last_attempt_at?->toDateString() ?? '0000-00-00')->exists();
            $lastAttempt = $entity->artwork?->last_attempt_at;
            $ttl = $entity->artwork?->status === 'missing' ? now()->subDays(config('services.cover_art_archive.missing_ttl_days', 30)) : now()->subHours(config('services.cover_art_archive.retry_ttl_hours', 24));
            if (! $releasedSinceAttempt && $lastAttempt?->gt($ttl)) {
                continue;
            }
            $releaseMbid = $entity->releaseGroup?->releases->flatMap(fn ($release) => $release->entity?->identifiers ?? [])
                ->where('namespace', 'musicbrainz.release')->where('status', 'active')->first()?->value;
            if ($releaseMbid === null) {
                continue;
            }
            if ($attempted++ >= max(1, $limit)) {
                break;
            }
            try {
                if ($this->artwork->ingest($entity, $releaseMbid, $releasedSinceAttempt)->status === 'ready') {
                    $updated++;
                }
            } catch (Throwable $exception) {
                Log::warning('Discovery artwork retry failed.', ['entity_id' => $entity->id, 'error_code' => class_basename($exception)]);
            }
        }

        return $updated;
    }
}
