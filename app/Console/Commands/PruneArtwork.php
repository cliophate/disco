<?php

namespace App\Console\Commands;

use App\Models\CatalogEntityArtwork;
use App\Models\PlexItemArtwork;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneArtwork extends Command
{
    protected $signature = 'disco:artwork-prune
        {--grace-days=7 : Keep unreferenced files for this many days}
        {--dry-run : Report without deleting}';

    protected $description = 'Remove unreferenced normalized artwork after a safety grace period';

    public function handle(): int
    {
        $disk = Storage::disk('artwork');
        $referenced = PlexItemArtwork::query()
            ->whereNotNull('storage_key')
            ->pluck('storage_key')
            ->merge(CatalogEntityArtwork::query()->whereNotNull('storage_key')->pluck('storage_key'))
            ->flip();
        $cutoff = now()->subDays(max(1, (int) $this->option('grace-days')))->getTimestamp();
        $deleted = 0;
        foreach (collect(['plex', 'cover-art-archive'])->flatMap(fn (string $prefix) => $disk->allFiles($prefix)) as $path) {
            if ($referenced->has($path) || $disk->lastModified($path) >= $cutoff) {
                continue;
            }
            if (! $this->option('dry-run')) {
                $disk->delete($path);
            }
            $deleted++;
        }

        $this->info(($this->option('dry-run') ? 'Would remove ' : 'Removed ')."{$deleted} unreferenced artwork files.");

        return self::SUCCESS;
    }
}
