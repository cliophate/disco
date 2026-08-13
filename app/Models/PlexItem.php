<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PlexItem extends Model
{
    use HasUuids;

    protected $table = 'library.plex_items';

    protected $fillable = [
        'plex_library_id', 'rating_key', 'item_type', 'parent_rating_key',
        'grandparent_rating_key', 'guid', 'title', 'sort_title', 'year', 'duration_ms',
        'index_number', 'disc_number', 'added_at_plex', 'updated_at_plex',
        'last_viewed_at', 'view_count', 'thumb_key', 'raw_metadata', 'last_synced_at',
        'removed_at',
    ];

    protected function casts(): array
    {
        return [
            'raw_metadata' => 'array',
            'added_at_plex' => 'datetime',
            'updated_at_plex' => 'datetime',
            'last_viewed_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(PlexLibrary::class, 'plex_library_id');
    }

    public function guids(): HasMany
    {
        return $this->hasMany(PlexItemGuid::class, 'plex_item_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(PlexEntityMatch::class, 'plex_item_id');
    }

    public function artwork(): HasOne
    {
        return $this->hasOne(PlexItemArtwork::class, 'plex_item_id');
    }

    public function mediaParts(): HasMany
    {
        return $this->hasMany(PlexMediaPart::class, 'plex_item_id');
    }
}
