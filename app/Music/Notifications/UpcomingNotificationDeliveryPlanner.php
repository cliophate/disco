<?php

namespace App\Music\Notifications;

use App\Models\UpcomingNotificationDelivery;
use App\Models\UpcomingReleaseNotification;

class UpcomingNotificationDeliveryPlanner
{
    public function __construct(private readonly GotifyClient $gotify) {}

    public function enqueue(UpcomingReleaseNotification $notification): void
    {
        if ($notification->status !== 'active' || ! $this->gotify->configured()) {
            return;
        }

        UpcomingNotificationDelivery::query()->firstOrCreate(
            ['notification_id' => $notification->id, 'channel' => 'gotify'],
            ['status' => 'pending', 'next_attempt_at' => now()],
        );
    }
}
