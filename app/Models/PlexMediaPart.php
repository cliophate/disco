<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlexMediaPart extends Model
{
    use HasUuids;

    protected $table = 'library.plex_media_parts';

    protected $fillable = [
        'plex_item_id', 'media_id', 'part_id', 'part_key', 'media_version', 'container', 'audio_codec',
        'channels', 'bit_depth', 'sample_rate_hz', 'bitrate_kbps', 'size_bytes',
        'duration_ms', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'channels' => 'integer',
            'bit_depth' => 'integer',
            'sample_rate_hz' => 'integer',
            'bitrate_kbps' => 'integer',
            'size_bytes' => 'integer',
            'duration_ms' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PlexItem::class, 'plex_item_id');
    }

    public function browserMimeType(): ?string
    {
        return match (true) {
            $this->audio_codec === 'flac' && $this->container === 'flac' => 'audio/flac',
            $this->audio_codec === 'alac' && in_array($this->container, ['m4a', 'mp4'], true) => 'audio/mp4; codecs="alac"',
            str_starts_with((string) $this->audio_codec, 'pcm_') && in_array($this->container, ['wav', 'wave'], true) => 'audio/wav',
            default => null,
        };
    }
}
