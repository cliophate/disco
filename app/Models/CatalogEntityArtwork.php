<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogEntityArtwork extends Model
{
    use HasUuids;

    protected $table = 'catalog.entity_artworks';

    protected $fillable = [
        'entity_id', 'status', 'source_release_mbid', 'source_image_id', 'source_hash',
        'content_sha256', 'storage_key', 'mime_type', 'size_bytes', 'width', 'height',
        'attempt_count', 'last_error_code', 'last_attempt_at', 'ingested_at',
    ];

    protected function casts(): array
    {
        return ['last_attempt_at' => 'datetime', 'ingested_at' => 'datetime'];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(CatalogEntity::class, 'entity_id');
    }
}
