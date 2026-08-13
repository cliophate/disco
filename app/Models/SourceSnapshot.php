<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceSnapshot extends Model
{
    use HasUuids;

    protected $table = 'source.snapshots';

    protected $fillable = [
        'source_object_id', 'retrieved_at', 'http_status', 'payload_hash', 'payload',
        'parser_version', 'expires_at',
    ];

    protected function casts(): array
    {
        return ['retrieved_at' => 'datetime', 'payload' => 'array', 'expires_at' => 'datetime'];
    }

    public function object(): BelongsTo
    {
        return $this->belongsTo(SourceObject::class, 'source_object_id');
    }
}
