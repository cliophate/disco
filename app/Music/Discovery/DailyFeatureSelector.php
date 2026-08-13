<?php

namespace App\Music\Discovery;

class DailyFeatureSelector
{
    /**
     * @param  iterable<array<string, mixed>>  $candidates
     * @return array<string, mixed>|null
     */
    public function select(iterable $candidates, string $calendarDay): ?array
    {
        return collect($candidates)
            ->filter(fn (array $candidate): bool => is_string(data_get($candidate, 'album.id'))
                && data_get($candidate, 'album.id') !== ''
                && data_get($candidate, 'album.identity_status') === 'confirmed')
            ->unique('album.id')
            ->sortBy(fn (array $candidate): string => sprintf('%d:%s', ($candidate['recently_presented'] ?? false) ? 1 : 0, hash('sha256', $calendarDay.'|'.data_get($candidate, 'album.id'))))
            ->first();
    }
}
