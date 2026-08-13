<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PlexSyncRun extends Model
{
    use HasUuids;

    protected $table = 'library.plex_sync_runs';

    protected $fillable = [
        'plex_library_id', 'status', 'counts', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'counts' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
