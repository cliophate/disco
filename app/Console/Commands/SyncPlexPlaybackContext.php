<?php

namespace App\Console\Commands;

use App\Music\Plex\PlexPlaybackContextSyncService;
use Illuminate\Console\Command;

class SyncPlexPlaybackContext extends Command
{
    protected $signature = 'disco:plex-playback-context';

    protected $description = 'Refresh short-lived read-only Plex playback context';

    public function handle(PlexPlaybackContextSyncService $sync): int
    {
        $result = $sync->sync();
        $this->info("Observed {$result['observed']} Plex music sessions; matched {$result['matched']} albums.");

        return self::SUCCESS;
    }
}
