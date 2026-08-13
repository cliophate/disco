<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecommendationFeedback extends Model
{
    use HasUuids;

    protected $table = 'discovery.recommendation_feedback';

    protected $fillable = [
        'user_id', 'entity_id', 'recommendation_item_id', 'action', 'reason', 'expires_at',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(RecommendationItem::class, 'recommendation_item_id');
    }
}
