<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlexServer extends Model
{
    use HasUuids;

    protected $table = 'library.plex_servers';

    protected $fillable = [
        'name', 'machine_identifier', 'machine_identifier_hash', 'version', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function libraries(): HasMany
    {
        return $this->hasMany(PlexLibrary::class, 'plex_server_id');
    }
}
