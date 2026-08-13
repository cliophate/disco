<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UpcomingReleaseItem extends Model
{
    use HasUuids;

    protected $table = 'discovery.upcoming_release_items';

    protected $fillable = [
        'generation_id', 'release_group_id', 'release_group_mbid', 'release_mbid',
        'title', 'artist_credit_name', 'artist_mbids', 'release_date', 'primary_type',
        'secondary_types', 'artwork_status', 'caa_release_mbid', 'caa_id',
        'listen_count', 'tags', 'general_rank', 'provenance',
    ];

    protected function casts(): array
    {
        return [
            'artist_mbids' => 'array',
            'release_date' => 'date:Y-m-d',
            'secondary_types' => 'array',
            'listen_count' => 'integer',
            'tags' => 'array',
            'provenance' => 'array',
        ];
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(UpcomingReleaseGeneration::class, 'generation_id');
    }

    public function releaseGroup(): BelongsTo
    {
        return $this->belongsTo(CatalogEntity::class, 'release_group_id');
    }
}
