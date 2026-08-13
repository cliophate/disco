<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecommendationItem extends Model
{
    use HasUuids;

    protected $table = 'discovery.recommendation_items';

    protected $fillable = [
        'run_id', 'entity_id', 'rank', 'score', 'component_scores', 'eligibility',
        'module_type', 'explanation_text', 'explanation_version',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:6',
            'component_scores' => 'array',
            'eligibility' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(RecommendationRun::class, 'run_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(CatalogEntity::class, 'entity_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(RecommendationEvidence::class, 'recommendation_item_id');
    }
}
