<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlexEntityMatch extends Model
{
    use HasUuids;

    protected $table = 'library.plex_entity_matches';

    protected $fillable = [
        'plex_item_id', 'entity_id', 'match_scope', 'status', 'method', 'confidence',
    ];

    protected function casts(): array
    {
        return ['confidence' => 'decimal:4'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PlexItem::class, 'plex_item_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(CatalogEntity::class, 'entity_id');
    }
}
