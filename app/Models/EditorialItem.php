<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EditorialItem extends Model
{
    use HasUuids;

    protected $table = 'discovery.editorial_items';

    protected $fillable = [
        'source_snapshot_id', 'source', 'feed_url', 'guid', 'canonical_url', 'headline',
        'excerpt', 'author', 'publisher', 'category', 'image_url', 'image_width', 'image_height',
        'published_at', 'retrieved_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'image_width' => 'integer',
            'image_height' => 'integer',
            'published_at' => 'immutable_datetime',
            'retrieved_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
