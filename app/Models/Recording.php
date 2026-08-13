<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recording extends Model
{
    protected $table = 'catalog.recordings';

    protected $primaryKey = 'entity_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['entity_id', 'duration_ms'];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(CatalogEntity::class, 'entity_id');
    }
}
