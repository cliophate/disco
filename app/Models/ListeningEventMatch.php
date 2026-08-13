<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ListeningEventMatch extends Model
{
    use HasUuids;

    protected $table = 'activity.listening_event_matches';

    protected $fillable = [
        'listening_event_id', 'recording_entity_id', 'release_group_entity_id',
        'plex_track_item_id', 'status', 'method', 'confidence', 'evidence',
        'source_present', 'last_seen_import_run_id',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:4',
            'evidence' => 'array',
            'source_present' => 'boolean',
        ];
    }
}
