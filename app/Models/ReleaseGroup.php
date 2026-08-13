<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReleaseGroup extends Model
{
    protected $table = 'catalog.release_groups';

    protected $primaryKey = 'entity_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'entity_id', 'primary_type', 'secondary_types', 'first_release_year',
        'first_release_month', 'first_release_day', 'date_precision',
    ];

    protected function casts(): array
    {
        return ['secondary_types' => 'array'];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(CatalogEntity::class, 'entity_id');
    }

    public function releases(): HasMany
    {
        return $this->hasMany(Release::class, 'release_group_id');
    }

    public function holdings(): HasMany
    {
        return $this->hasMany(Holding::class, 'release_group_id');
    }
}
