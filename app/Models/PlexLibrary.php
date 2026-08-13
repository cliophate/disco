<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlexLibrary extends Model
{
    use HasUuids;

    protected $table = 'library.plex_libraries';

    protected $fillable = [
        'plex_server_id', 'section_key', 'section_uuid', 'title', 'library_type',
        'agent', 'scanner', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return ['last_synced_at' => 'datetime'];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(PlexServer::class, 'plex_server_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PlexItem::class, 'plex_library_id');
    }
}
