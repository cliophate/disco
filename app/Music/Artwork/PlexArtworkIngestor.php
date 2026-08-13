<?php

namespace App\Music\Artwork;

use App\Models\PlexItem;
use App\Models\PlexItemArtwork;
use App\Music\Plex\PlexClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class PlexArtworkIngestor
{
    public function __construct(
        private readonly PlexClient $client,
        private readonly RasterArtworkProcessor $processor,
    ) {}

    public function ingest(PlexItem $item): PlexItemArtwork
    {
        $thumbHash = $item->thumb_key === null ? null : hash('sha256', $item->thumb_key);
        $artwork = PlexItemArtwork::query()->firstOrCreate(
            ['plex_item_id' => $item->id],
            ['status' => $thumbHash === null ? 'missing' : 'pending', 'observed_thumb_hash' => $thumbHash],
        );

        if ($item->removed_at !== null || $item->thumb_key === null || $thumbHash === null) {
            $artwork->update([
                'status' => 'missing',
                'observed_thumb_hash' => $thumbHash,
                'ingested_thumb_hash' => null,
                'content_sha256' => null,
                'storage_key' => null,
                'mime_type' => null,
                'size_bytes' => null,
                'width' => null,
                'height' => null,
                'last_error_code' => null,
            ]);

            return $artwork->refresh();
        }

        if ($artwork->status === 'ready'
            && hash_equals((string) $artwork->ingested_thumb_hash, $thumbHash)
            && $artwork->storage_key !== null
            && Storage::disk('artwork')->exists($artwork->storage_key)) {
            return $artwork;
        }

        return Cache::lock("disco:plex-artwork:{$item->id}", 120)->block(10, function () use ($artwork, $item, $thumbHash): PlexItemArtwork {
            $artwork->refresh();
            if ($artwork->status === 'ready'
                && hash_equals((string) $artwork->ingested_thumb_hash, $thumbHash)
                && $artwork->storage_key !== null
                && Storage::disk('artwork')->exists($artwork->storage_key)) {
                return $artwork;
            }

            $artwork->update([
                'observed_thumb_hash' => $thumbHash,
                'attempt_count' => $artwork->attempt_count + 1,
                'last_attempt_at' => now(),
                'last_error_code' => null,
            ]);

            try {
                $this->assertLibraryBinding($item);
                $download = $this->processor->normalize(
                    $this->client->artwork($item->thumb_key, $item->rating_key, $item->parent_rating_key),
                );
                $checksum = hash('sha256', $download['body']);
                $storageKey = "plex/{$checksum[0]}{$checksum[1]}/{$checksum}.{$download['extension']}";
                Storage::disk('artwork')->put($storageKey, $download['body']);

                $artwork->update([
                    'status' => 'ready',
                    'ingested_thumb_hash' => $thumbHash,
                    'content_sha256' => $checksum,
                    'storage_key' => $storageKey,
                    'mime_type' => $download['mime_type'],
                    'size_bytes' => strlen($download['body']),
                    'width' => $download['width'],
                    'height' => $download['height'],
                    'last_error_code' => null,
                    'ingested_at' => now(),
                ]);
            } catch (Throwable $exception) {
                $errorCode = class_basename($exception);
                $artwork->update([
                    'status' => $artwork->storage_key === null ? 'failed' : 'stale',
                    'last_error_code' => $errorCode,
                ]);
                Log::warning('Plex artwork ingestion failed.', [
                    'plex_item_id' => $item->id,
                    'error_code' => $errorCode,
                ]);
            }

            return $artwork->refresh();
        });
    }

    private function assertLibraryBinding(PlexItem $item): void
    {
        $item->loadMissing('library.server');
        $expectedMachine = (string) config('services.plex.expected_machine_identifier');
        $expectedLibrary = (string) config('services.plex.expected_library_uuid');
        if ($expectedMachine === '' || $expectedLibrary === ''
            || ! hash_equals($expectedMachine, (string) $item->library?->server?->machine_identifier)
            || ! hash_equals($expectedLibrary, (string) $item->library?->section_uuid)) {
            throw new RuntimeException('Plex artwork item is outside the pinned server and library.');
        }
    }
}
