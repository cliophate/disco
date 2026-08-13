<?php

namespace App\Console\Commands;

use App\Music\Plex\PlexSyncService;
use Illuminate\Console\Command;

class SyncPlex extends Command
{
    protected $signature = 'disco:plex-sync
        {--dry-run : Read Plex without changing the database}
        {--allow-empty-types : Explicitly allow an existing artist, album, or track type to reconcile to zero items}';

    protected $description = 'Synchronize the configured read-only Plex music library';

    public function handle(PlexSyncService $sync): int
    {
        $counts = $sync->sync(
            (bool) $this->option('dry-run'),
            (bool) $this->option('allow-empty-types'),
        );

        $this->table(['Library', 'Artists', 'Albums', 'Tracks'], [[
            $counts['library'], $counts['artists'], $counts['albums'], $counts['tracks'],
        ]]);

        $this->info($this->option('dry-run') ? 'Dry run complete.' : 'Plex synchronization complete.');

        return self::SUCCESS;
    }
}
