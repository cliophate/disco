<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArtistDiscographyItem extends Model
{
    use HasUuids;

    protected $table = 'discovery.artist_discography_items';

    protected $fillable = [
        'generation_id', 'release_group_id', 'release_group_mbid', 'title', 'artist_credit',
        'primary_type', 'secondary_types', 'first_release_year', 'first_release_month',
        'first_release_day', 'date_precision', 'official_release_mbid',
        'official_release_date', 'position',
    ];

    protected function casts(): array
    {
        return [
            'artist_credit' => 'array',
            'secondary_types' => 'array',
            'official_release_date' => 'date:Y-m-d',
        ];
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(ArtistDiscographyGeneration::class, 'generation_id');
    }

    public function releaseGroup(): BelongsTo
    {
        return $this->belongsTo(CatalogEntity::class, 'release_group_id');
    }
}
