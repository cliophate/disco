<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceObject extends Model
{
    use HasUuids;

    protected $table = 'source.objects';

    protected $fillable = [
        'provider_id', 'object_type', 'external_id', 'canonical_url', 'first_seen_at', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(SourceProvider::class, 'provider_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(SourceSnapshot::class, 'source_object_id');
    }

    public function resolutions(): HasMany
    {
        return $this->hasMany(EntityResolution::class, 'source_object_id');
    }
}
