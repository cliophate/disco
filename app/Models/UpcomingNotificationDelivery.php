<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UpcomingNotificationDelivery extends Model
{
    use HasUuids;

    protected $table = 'discovery.upcoming_notification_deliveries';

    protected $fillable = [
        'notification_id', 'channel', 'status', 'attempt_count', 'attempted_at', 'next_attempt_at',
        'delivered_at', 'external_id', 'error_code', 'skip_reason',
    ];

    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'attempted_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(UpcomingReleaseNotification::class, 'notification_id');
    }
}
