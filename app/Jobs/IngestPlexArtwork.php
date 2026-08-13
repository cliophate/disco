<?php

namespace App\Jobs;

use App\Models\PlexItem;
use App\Music\Artwork\PlexArtworkIngestor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class IngestPlexArtwork implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    public function __construct(public readonly string $plexItemId) {}

    public function uniqueId(): string
    {
        return $this->plexItemId;
    }

    public function handle(PlexArtworkIngestor $ingestor): void
    {
        $item = PlexItem::query()->find($this->plexItemId);
        if ($item !== null) {
            $artwork = $ingestor->ingest($item);
            if (in_array($artwork->status, ['failed', 'stale'], true)) {
                throw new RuntimeException("Artwork ingestion ended in {$artwork->status} state.");
            }
        }
    }
}
