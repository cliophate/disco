<?php

namespace App\Music\Artwork;

use App\Models\CatalogEntity;
use App\Models\CatalogEntityArtwork;
use App\Music\CoverArtArchive\CoverArtArchiveClient;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class CoverArtArchiveIngestor
{
    public function __construct(
        private readonly CoverArtArchiveClient $client,
        private readonly RasterArtworkProcessor $processor,
        private readonly ReleaseGroupArtworkCandidateResolver $candidates,
    ) {}

    public function ingest(CatalogEntity $entity, string $releaseMbid, bool $refresh = false): CatalogEntityArtwork
    {
        if ($entity->kind !== 'release_group' || $entity->status !== 'active') {
            throw new RuntimeException('Cover artwork requires an active release-group entity.');
        }
        $this->candidates->assertBasis($entity, $releaseMbid);

        return Cache::lock("disco:catalog-artwork:{$entity->id}", 180)->block(30, function () use ($entity, $refresh, $releaseMbid): CatalogEntityArtwork {
            $artwork = CatalogEntityArtwork::query()->firstOrCreate(['entity_id' => $entity->id], ['status' => 'pending']);
            $hasValidContent = $this->hasValidContent($artwork);
            if (! $refresh && $artwork->status === 'ready' && $hasValidContent) {
                return $artwork;
            }
            if (in_array($artwork->status, ['ready', 'stale'], true) && ! $hasValidContent) {
                $artwork->update([
                    'status' => 'pending', 'source_release_mbid' => null, 'source_image_id' => null, 'source_hash' => null,
                    'content_sha256' => null, 'storage_key' => null, 'mime_type' => null, 'size_bytes' => null,
                    'width' => null, 'height' => null, 'ingested_at' => null,
                ]);
            }
            $dueSince = match ($artwork->status) {
                'missing' => now()->subDays((int) config('services.cover_art_archive.missing_ttl_days', 30)),
                'failed', 'stale' => now()->subHours((int) config('services.cover_art_archive.retry_ttl_hours', 24)),
                default => null,
            };
            if (! $refresh && $dueSince !== null && $artwork->last_attempt_at?->isAfter($dueSince)) {
                return $artwork;
            }
            $attempt = ['attempt_count' => $artwork->attempt_count + 1, 'last_attempt_at' => now(), 'last_error_code' => null];
            try {
                $lastFailure = null;
                $basisMbid = strtolower($releaseMbid);
                foreach ([$basisMbid] as $candidateMbid) {
                    try {
                        $front = $this->client->front($candidateMbid);
                        if ($front === null) {
                            continue;
                        }
                        $normalized = $this->processor->normalize($this->client->download($front['release_mbid'], $front['image_id']));
                    } catch (Exception $exception) {
                        $lastFailure = $exception;

                        continue;
                    }

                    return $this->store($artwork, $attempt, $front, $normalized);
                }
                foreach ($this->candidates->candidates($entity, $releaseMbid, $refresh) as $candidateMbid) {
                    if ($candidateMbid === $basisMbid) {
                        continue;
                    }
                    try {
                        $front = $this->client->front($candidateMbid);
                        if ($front === null) {
                            continue;
                        }
                        $normalized = $this->processor->normalize($this->client->download($front['release_mbid'], $front['image_id']));
                    } catch (Exception $exception) {
                        $lastFailure = $exception;

                        continue;
                    }

                    return $this->store($artwork, $attempt, $front, $normalized);
                }
                if ($lastFailure !== null) {
                    throw $lastFailure;
                }
                $artwork->update($attempt + ['status' => $artwork->content_sha256 === null ? 'missing' : 'stale']);

                return $artwork->refresh();
            } catch (Throwable $exception) {
                $artwork->update($attempt + [
                    'status' => $artwork->content_sha256 === null ? 'failed' : 'stale',
                    'last_error_code' => class_basename($exception),
                ]);
                throw $exception;
            }
        });
    }

    private function hasValidContent(CatalogEntityArtwork $artwork): bool
    {
        if ($artwork->storage_key === null || $artwork->content_sha256 === null || ! Storage::disk('artwork')->exists($artwork->storage_key)) {
            return false;
        }

        return hash_equals($artwork->content_sha256, hash('sha256', Storage::disk('artwork')->get($artwork->storage_key)));
    }

    /** @param array<string, mixed> $attempt
     * @param  array{release_mbid:string,image_id:string}  $front
     * @param  array{body:string,mime_type:string,width:int,height:int,extension:string}  $normalized
     */
    private function store(CatalogEntityArtwork $artwork, array $attempt, array $front, array $normalized): CatalogEntityArtwork
    {
        $contentHash = hash('sha256', $normalized['body']);
        $storageKey = 'cover-art-archive/'.substr($contentHash, 0, 2)."/{$contentHash}.{$normalized['extension']}";
        if (! Storage::disk('artwork')->exists($storageKey)) {
            Storage::disk('artwork')->put($storageKey, $normalized['body']);
        }
        $artwork->update($attempt + [
            'status' => 'ready',
            'source_release_mbid' => $front['release_mbid'],
            'source_image_id' => $front['image_id'],
            'source_hash' => hash('sha256', $front['release_mbid'].':'.$front['image_id'].':1200'),
            'content_sha256' => $contentHash,
            'storage_key' => $storageKey,
            'mime_type' => $normalized['mime_type'],
            'size_bytes' => strlen($normalized['body']),
            'width' => $normalized['width'],
            'height' => $normalized['height'],
            'ingested_at' => now(),
        ]);

        return $artwork->refresh();
    }
}
