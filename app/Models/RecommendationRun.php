<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecommendationRun extends Model
{
    use HasUuids;

    protected $table = 'discovery.recommendation_runs';

    protected $fillable = [
        'user_id', 'intent', 'input', 'algorithm_version', 'configuration_hash',
        'random_seed', 'catalog_version', 'status', 'generated_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'generated_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RecommendationItem::class, 'run_id')->orderBy('rank');
    }
}
