<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ListeningEvent extends Model
{
    use HasUuids;

    protected $table = 'activity.listening_events';

    public const UPDATED_AT = null;

    protected $fillable = [
        'source_account_id', 'source_snapshot_id', 'fingerprint', 'listened_at',
        'listened_at_epoch', 'supplied_artist', 'supplied_release', 'supplied_track',
        'recording_msid', 'recording_mbid', 'release_mbid', 'release_group_mbid',
        'identifier_conflicts', 'duration_ms', 'music_service_name', 'media_player', 'submission_client',
        'raw_additional_info',
    ];

    protected function casts(): array
    {
        return [
            'listened_at' => 'immutable_datetime',
            'identifier_conflicts' => 'array',
            'raw_additional_info' => 'array',
        ];
    }
}
