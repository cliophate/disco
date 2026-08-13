<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ArtistFollow extends Model
{
    use HasUuids;

    protected $table = 'discovery.artist_follows';

    protected $fillable = ['user_id', 'artist_entity_id'];
}
