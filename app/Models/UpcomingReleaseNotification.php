<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UpcomingReleaseNotification extends Model
{
    use HasUuids;

    protected $table = 'discovery.upcoming_release_notifications';

    protected $fillable = [
        'user_id', 'release_group_id', 'source_snapshot_id', 'release_group_mbid', 'release_mbid',
        'title', 'artist_credit_name', 'artist_mbids', 'release_date', 'primary_type',
        'personalization_type', 'personalization_reason', 'source_provider', 'source_provider_name',
        'source_url', 'status', 'resolution_reason', 'absence_count', 'last_seen_generation_id',
        'last_evaluated_generation_id', 'read_at',
    ];

    protected function casts(): array
    {
        return [
            'artist_mbids' => 'array',
            'release_date' => 'date:Y-m-d',
            'absence_count' => 'integer',
            'read_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function releaseGroup(): BelongsTo
    {
        return $this->belongsTo(CatalogEntity::class, 'release_group_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(UpcomingNotificationDelivery::class, 'notification_id');
    }
}
