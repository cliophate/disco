<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RecommendationImpression extends Model
{
    use HasUuids;

    protected $table = 'discovery.recommendation_impressions';

    protected $fillable = [
        'user_id', 'recommendation_item_id', 'entity_id', 'surface', 'context_key', 'context', 'presented_at',
    ];

    protected function casts(): array
    {
        return ['context' => 'array', 'presented_at' => 'datetime'];
    }
}
