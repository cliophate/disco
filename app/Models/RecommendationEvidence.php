<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecommendationEvidence extends Model
{
    use HasUuids;

    protected $table = 'discovery.recommendation_evidence';

    protected $fillable = [
        'recommendation_item_id', 'evidence_type', 'subject_entity_id', 'predicate',
        'object_entity_id', 'source_provider_id', 'source_slug', 'weight', 'display_text',
    ];

    protected function casts(): array
    {
        return ['weight' => 'decimal:6'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(RecommendationItem::class, 'recommendation_item_id');
    }
}
