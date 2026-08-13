<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UpcomingReleaseGeneration extends Model
{
    use HasUuids;

    protected $table = 'discovery.upcoming_release_generations';

    protected $fillable = [
        'source_snapshot_id', 'algorithm_version', 'horizon_days', 'horizon_reason',
        'coverage', 'generated_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'coverage' => 'array',
            'generated_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(UpcomingReleaseItem::class, 'generation_id');
    }
}
