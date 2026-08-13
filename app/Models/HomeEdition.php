<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class HomeEdition extends Model
{
    use HasUuids;

    protected $table = 'discovery.home_editions';

    protected $fillable = [
        'user_id', 'recommendation_run_id', 'version_hash', 'algorithm_version',
        'facts_as_of', 'payload', 'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'facts_as_of' => 'datetime',
            'payload' => 'array',
            'generated_at' => 'datetime',
        ];
    }
}
