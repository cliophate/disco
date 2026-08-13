<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditEdge extends Model
{
    use HasUuids;

    protected $table = 'catalog.credit_edges';

    protected $fillable = [
        'subject_entity_id', 'source_key', 'role', 'credited_name', 'target_entity_id',
        'source_snapshot_id', 'position', 'attributes',
    ];

    protected function casts(): array
    {
        return ['attributes' => 'array'];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(CatalogEntity::class, 'subject_entity_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(CatalogEntity::class, 'target_entity_id');
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(SourceSnapshot::class, 'source_snapshot_id');
    }
}
