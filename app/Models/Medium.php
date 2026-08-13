<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Medium extends Model
{
    use HasUuids;

    protected $table = 'catalog.media';

    protected $fillable = ['release_id', 'position', 'title', 'format'];

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'release_id');
    }

    public function tracks(): HasMany
    {
        return $this->hasMany(MediumTrack::class, 'medium_id')->orderBy('position');
    }
}
