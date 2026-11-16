<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DiscoveryCandidate extends Model
{
    use HasUuids;

    protected $table = 'discovery.candidates';

    protected $guarded = [];

    protected $primaryKey = 'artist_mbid';

    protected function casts(): array
    {
        return ['seeds' => 'array', 'score' => 'float'];
    }
}
