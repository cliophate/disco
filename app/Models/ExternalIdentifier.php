<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalIdentifier extends Model
{
    use HasUuids;

    protected $table = 'catalog.external_identifiers';

    protected $fillable = ['entity_id', 'namespace', 'value', 'status'];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(CatalogEntity::class, 'entity_id');
    }
}
