<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArtistDiscographyGeneration extends Model
{
    use HasUuids;

    protected $table = 'discovery.artist_discography_generations';

    protected $fillable = [
        'artist_entity_id', 'artist_mbid', 'source_total', 'page_count', 'truncated',
        'algorithm_version', 'generated_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'truncated' => 'boolean',
            'generated_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(CatalogEntity::class, 'artist_entity_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ArtistDiscographyItem::class, 'generation_id')->orderBy('position');
    }
}
