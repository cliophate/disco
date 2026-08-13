<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Agent extends Model
{
    protected $table = 'catalog.agents';

    protected $primaryKey = 'entity_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['entity_id', 'agent_type', 'begin_year', 'end_year'];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(CatalogEntity::class, 'entity_id');
    }
}
