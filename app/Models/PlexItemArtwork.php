<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlexItemArtwork extends Model
{
    use HasUuids;

    protected $table = 'library.plex_item_artworks';

    protected $fillable = [
        'plex_item_id', 'status', 'observed_thumb_hash', 'ingested_thumb_hash',
        'content_sha256', 'storage_key', 'mime_type', 'size_bytes', 'width', 'height',
        'attempt_count', 'last_error_code', 'last_attempt_at', 'ingested_at',
    ];

    protected function casts(): array
    {
        return [
            'last_attempt_at' => 'datetime',
            'ingested_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PlexItem::class, 'plex_item_id');
    }
}
