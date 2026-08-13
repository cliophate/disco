<?php

namespace App\Console\Commands;

use App\Music\Editorial\PitchforkRssRefresher;
use Illuminate\Console\Command;

class RefreshPitchforkRss extends Command
{
    protected $signature = 'disco:pitchfork-rss {--force : Enable and run this optional integration for an approved deployment}';

    protected $description = 'Cache approved fields from official Pitchfork RSS feeds';

    public function handle(PitchforkRssRefresher $refresher): int
    {
        $result = $refresher->refresh((bool) $this->option('force'));
        $this->table(['Feeds', 'Created', 'Refreshed', 'Pruned'], [array_values($result)]);

        return self::SUCCESS;
    }
}
