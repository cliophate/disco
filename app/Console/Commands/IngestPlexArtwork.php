<?php

namespace App\Console\Commands;

use App\Models\PlexItem;
use App\Music\Artwork\PlexArtworkIngestor;
use Illuminate\Console\Command;

class IngestPlexArtwork extends Command
{
    protected $signature = 'disco:plex-artwork
        {--type=album : Plex item type to ingest: album or artist}
        {--limit=0 : Maximum number of items; zero means all}
        {--failed-only : Retry only missing or failed artwork}';

    protected $description = 'Cache validated Plex artwork for safe same-origin display';

    public function handle(PlexArtworkIngestor $ingestor): int
    {
        $type = (string) $this->option('type');
        if (! in_array($type, ['album', 'artist'], true)) {
            $this->error('Artwork type must be album or artist.');

            return self::INVALID;
        }

        $limit = max(0, (int) $this->option('limit'));
        $query = PlexItem::query()
            ->where('item_type', $type)
            ->whereNull('removed_at')
            ->where(fn ($builder) => $builder
                ->whereNotNull('thumb_key')
                ->orWhereHas('artwork'))
            ->with('artwork')
            ->orderBy('id');
        if ($this->option('failed-only')) {
            $query->where(fn ($builder) => $builder
                ->whereDoesntHave('artwork')
                ->orWhereHas('artwork', fn ($artwork) => $artwork->whereIn('status', ['stale', 'failed', 'missing'])));
        }

        $items = $limit > 0 ? $query->limit($limit)->get() : $query->get();
        $bar = $this->output->createProgressBar($items->count());
        $counts = ['ready' => 0, 'stale' => 0, 'failed' => 0, 'missing' => 0, 'pending' => 0];
        foreach ($items as $item) {
            $artwork = $ingestor->ingest($item);
            $counts[$artwork->status]++;
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);
        $this->table(['Ready', 'Stale', 'Failed', 'Missing', 'Pending'], [[
            $counts['ready'], $counts['stale'], $counts['failed'], $counts['missing'], $counts['pending'],
        ]]);

        return $counts['failed'] === 0 && $counts['stale'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
