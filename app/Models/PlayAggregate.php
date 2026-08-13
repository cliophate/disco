<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayAggregate extends Model
{
    protected $table = 'activity.play_aggregates';

    protected $primaryKey = 'release_group_entity_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'release_group_entity_id', 'play_count', 'first_listened_at', 'last_listened_at',
    ];

    protected function casts(): array
    {
        return [
            'first_listened_at' => 'datetime',
            'last_listened_at' => 'datetime',
        ];
    }
}
