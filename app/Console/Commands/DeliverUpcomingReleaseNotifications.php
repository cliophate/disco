<?php

namespace App\Console\Commands;

use App\Music\Notifications\UpcomingNotificationDeliveryService;
use Illuminate\Console\Command;

class DeliverUpcomingReleaseNotifications extends Command
{
    protected $signature = 'disco:upcoming-notification-delivery {--limit=50 : Maximum delivery attempts}';

    protected $description = 'Deliver queued upcoming-release alerts through configured channels';

    public function handle(UpcomingNotificationDeliveryService $deliveries): int
    {
        $result = $deliveries->deliver((int) $this->option('limit'));
        $this->table(['Requested', 'Delivered', 'Failed', 'Skipped'], [[
            $result['requested'], $result['delivered'], $result['failed'], $result['skipped'],
        ]]);

        return self::SUCCESS;
    }
}
