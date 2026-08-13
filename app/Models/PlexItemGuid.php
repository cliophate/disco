<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlexItemGuid extends Model
{
    use HasUuids;

    protected $table = 'library.plex_item_guids';

    protected $fillable = ['plex_item_id', 'namespace', 'value'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(PlexItem::class, 'plex_item_id');
    }
}
