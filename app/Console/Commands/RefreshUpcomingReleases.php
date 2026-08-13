<?php

namespace App\Console\Commands;

use App\Music\Discovery\UpcomingReleaseRefresher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class RefreshUpcomingReleases extends Command
{
    protected $signature = 'disco:upcoming-releases {--date= : UTC pivot date for a deterministic refresh}';

    protected $description = 'Cache exact upcoming album and EP releases from ListenBrainz';

    public function handle(UpcomingReleaseRefresher $refresher): int
    {
        $date = $this->option('date');
        $result = $refresher->refresh($date === null ? null : CarbonImmutable::createFromFormat('!Y-m-d', (string) $date));
        $this->table(['Generation', 'Horizon', 'Items'], [[
            $result['generation_id'],
            $result['horizon_days'].' days',
            $result['items'],
        ]]);
        $this->line($result['reason']);

        return self::SUCCESS;
    }
}
