<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntityMetadata extends Model
{
    protected $table = 'catalog.entity_metadata';

    protected $primaryKey = 'entity_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'entity_id', 'source_provider', 'genres', 'primary_type', 'country_code',
        'area', 'begin_year', 'begin_month', 'begin_day', 'begin_precision',
        'end_year', 'end_month', 'end_day', 'end_precision', 'first_release_year',
        'first_release_month', 'first_release_day', 'first_release_precision', 'disambiguation',
        'artist_credit', 'external_links', 'attributes', 'enriched_at',
    ];

    protected function casts(): array
    {
        return [
            'genres' => 'array',
            'artist_credit' => 'array',
            'external_links' => 'array',
            'attributes' => 'array',
            'enriched_at' => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(CatalogEntity::class, 'entity_id');
    }
}
