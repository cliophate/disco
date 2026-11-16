<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DiscoveryPass extends Model
{
    use HasUuids;

    protected $table = 'discovery.passes';

    protected $guarded = [];

    protected function casts(): array
    {
        return [];
    }
}
