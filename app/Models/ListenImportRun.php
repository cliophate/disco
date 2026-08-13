<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ListenImportRun extends Model
{
    use HasUuids;

    protected $table = 'activity.listen_import_runs';

    protected $fillable = [
        'source_account_id', 'mode', 'status', 'start_cursor', 'end_cursor',
        'counts', 'errors', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'start_cursor' => 'array',
            'end_cursor' => 'array',
            'counts' => 'array',
            'errors' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
