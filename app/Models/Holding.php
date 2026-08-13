<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Holding extends Model
{
    use HasUuids;

    protected $table = 'library.holdings';

    protected $fillable = [
        'release_group_id', 'release_id', 'plex_album_item_id',
        'ownership_type', 'is_primary_playback_copy',
    ];

    protected function casts(): array
    {
        return ['is_primary_playback_copy' => 'boolean'];
    }

    public function plexAlbum(): BelongsTo
    {
        return $this->belongsTo(PlexItem::class, 'plex_album_item_id');
    }

    public function releaseGroup(): BelongsTo
    {
        return $this->belongsTo(ReleaseGroup::class, 'release_group_id');
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'release_id');
    }
}
