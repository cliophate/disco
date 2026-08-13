<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CatalogEntity extends Model
{
    use HasUuids;

    protected $table = 'catalog.entities';

    protected $fillable = [
        'kind', 'status', 'redirect_entity_id', 'canonical_name', 'sort_name', 'disambiguation',
    ];

    public function agent(): HasOne
    {
        return $this->hasOne(Agent::class, 'entity_id');
    }

    public function releaseGroup(): HasOne
    {
        return $this->hasOne(ReleaseGroup::class, 'entity_id');
    }

    public function recording(): HasOne
    {
        return $this->hasOne(Recording::class, 'entity_id');
    }

    public function release(): HasOne
    {
        return $this->hasOne(Release::class, 'entity_id');
    }

    public function metadata(): HasOne
    {
        return $this->hasOne(EntityMetadata::class, 'entity_id');
    }

    public function artwork(): HasOne
    {
        return $this->hasOne(CatalogEntityArtwork::class, 'entity_id');
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(ExternalIdentifier::class, 'entity_id');
    }

    public function narratives(): HasMany
    {
        return $this->hasMany(EntityNarrative::class, 'entity_id');
    }

    public function credits(): HasMany
    {
        return $this->hasMany(CreditEdge::class, 'subject_entity_id');
    }

    public function plexMatches(): HasMany
    {
        return $this->hasMany(PlexEntityMatch::class, 'entity_id');
    }
}
