<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiscogsEnrichmentState extends Model
{
    protected $table = 'source.discogs_enrichment_states';

    protected $primaryKey = 'entity_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'entity_id', 'status', 'attempted_at', 'retry_at', 'error_code', 'evidence',
    ];

    protected function casts(): array
    {
        return [
            'attempted_at' => 'datetime',
            'retry_at' => 'datetime',
            'evidence' => 'array',
        ];
    }
}
