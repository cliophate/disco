<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlbumListItem extends Model
{
    use HasUuids;

    protected $table = 'discovery.album_list_items';

    protected $fillable = [
        'user_id', 'release_group_entity_id', 'status', 'note', 'source',
        'wanted_at', 'listened_at', 'removed_at', 'state_changed_at',
    ];

    protected function casts(): array
    {
        return [
            'wanted_at' => 'datetime',
            'listened_at' => 'datetime',
            'removed_at' => 'datetime',
            'state_changed_at' => 'datetime',
        ];
    }

    public function album(): BelongsTo
    {
        return $this->belongsTo(CatalogEntity::class, 'release_group_entity_id');
    }
}
