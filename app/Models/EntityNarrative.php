<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntityNarrative extends Model
{
    use HasUuids;

    protected $table = 'catalog.entity_narratives';

    protected $fillable = [
        'entity_id', 'provider_slug', 'kind', 'language', 'status', 'body', 'source_url',
        'external_id', 'content_sha256', 'license_name', 'license_url', 'fetched_at',
    ];

    protected function casts(): array
    {
        return ['fetched_at' => 'datetime'];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(CatalogEntity::class, 'entity_id');
    }
}
