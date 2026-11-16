<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DiscoveryBatch extends Model
{
    use HasUuids;

    protected $table = 'discovery.batches';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['summary' => 'array', 'period' => 'immutable_date', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }
}
