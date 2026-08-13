<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SourceAssertion extends Model
{
    use HasUuids;

    protected $table = 'source.assertions';

    protected $fillable = [
        'snapshot_id', 'subject_entity_id', 'predicate', 'value', 'status', 'confidence',
    ];

    protected function casts(): array
    {
        return ['value' => 'array', 'confidence' => 'decimal:4'];
    }
}
