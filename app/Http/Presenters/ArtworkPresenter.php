<?php

namespace App\Http\Presenters;

use App\Models\CatalogEntity;
use App\Models\PlexItem;

class ArtworkPresenter
{
    /** @return array<string, int|string>|null */
    public function for(?PlexItem $item): ?array
    {
        $artwork = $item?->artwork;
        if ($artwork === null
            || ! in_array($artwork->status, ['ready', 'stale'], true)
            || $artwork->content_sha256 === null) {
            return null;
        }

        return [
            'id' => $artwork->id,
            'url' => route('api.artwork', [
                'artwork' => $artwork->id,
                'checksum' => $artwork->content_sha256,
            ], absolute: false),
            'width' => $artwork->width,
            'height' => $artwork->height,
        ];
    }

    /** @return array<string, int|string>|null */
    public function forEntity(CatalogEntity $entity): ?array
    {
        $artwork = $entity->artwork;
        if ($artwork === null
            || ! in_array($artwork->status, ['ready', 'stale'], true)
            || $artwork->content_sha256 === null) {
            return null;
        }

        return [
            'id' => $artwork->id,
            'url' => route('api.entity-artwork', [
                'artwork' => $artwork->id,
                'checksum' => $artwork->content_sha256,
            ], absolute: false),
            'width' => $artwork->width,
            'height' => $artwork->height,
        ];
    }
}
