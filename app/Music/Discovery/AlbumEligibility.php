<?php

namespace App\Music\Discovery;

class AlbumEligibility
{
    public function allows(array $group, ?string $artistMbid = null): bool
    {
        if (! in_array(strtolower((string) ($group['primary-type'] ?? '')), ['album', 'ep'], true)) {
            return false;
        }
        $secondary = array_map(fn ($type) => strtolower((string) $type), $group['secondary-types'] ?? []);
        if (array_intersect($secondary, config('discovery.excluded_secondary_types', [])) !== []) {
            return false;
        }

        return $artistMbid === null || strtolower((string) data_get($group, 'artist-credit.0.artist.id')) === $artistMbid;
    }
}
