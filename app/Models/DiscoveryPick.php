<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DiscoveryPick extends Model
{
    use HasUuids;

    protected $table = 'discovery.picks';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['seeds' => 'array', 'in_plex_at' => 'immutable_datetime', 'played_at' => 'immutable_datetime', 'passed_at' => 'immutable_datetime'];
    }
}
