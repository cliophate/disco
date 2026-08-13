<?php

namespace App\Console\Commands;

use App\Music\Discovery\UpcomingReleaseNotificationGenerator;
use Illuminate\Console\Command;

class GenerateUpcomingReleaseNotifications extends Command
{
    protected $signature = 'disco:upcoming-notifications {--max-items= : Fail without changes when the generation exceeds this size}';

    protected $description = 'Generate durable owner notifications from the cached upcoming-release feed';

    public function handle(UpcomingReleaseNotificationGenerator $generator): int
    {
        $option = $this->option('max-items');
        $result = $generator->generate($option === null ? null : (int) $option);
        $this->table(['Generation', 'Owners', 'Items', 'Created', 'Updated'], [[
            $result['generation_id'], $result['owners'], $result['items'], $result['created'], $result['updated'],
        ]]);

        return self::SUCCESS;
    }
}
