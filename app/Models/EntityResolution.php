<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntityResolution extends Model
{
    use HasUuids;

    protected $table = 'source.entity_resolutions';

    protected $fillable = [
        'source_object_id', 'entity_id', 'resolution_scope', 'status', 'method', 'confidence',
        'algorithm_version', 'evidence',
    ];

    protected function casts(): array
    {
        return ['confidence' => 'decimal:4', 'evidence' => 'array'];
    }

    public function object(): BelongsTo
    {
        return $this->belongsTo(SourceObject::class, 'source_object_id');
    }
}
