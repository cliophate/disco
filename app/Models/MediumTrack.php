<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediumTrack extends Model
{
    use HasUuids;

    protected $table = 'catalog.medium_tracks';

    protected $fillable = [
        'medium_id', 'recording_id', 'position', 'number_text', 'title', 'duration_ms',
    ];

    public function medium(): BelongsTo
    {
        return $this->belongsTo(Medium::class, 'medium_id');
    }

    public function recording(): BelongsTo
    {
        return $this->belongsTo(Recording::class, 'recording_id');
    }
}
