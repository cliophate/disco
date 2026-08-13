<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Release extends Model
{
    protected $table = 'catalog.releases';

    protected $primaryKey = 'entity_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'entity_id', 'release_group_id', 'status', 'country_code', 'barcode',
        'release_year', 'release_month', 'release_day', 'date_precision', 'edition_summary',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(CatalogEntity::class, 'entity_id');
    }

    public function releaseGroup(): BelongsTo
    {
        return $this->belongsTo(ReleaseGroup::class, 'release_group_id');
    }

    public function holdings(): HasMany
    {
        return $this->hasMany(Holding::class, 'release_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(Medium::class, 'release_id')->orderBy('position');
    }
}
