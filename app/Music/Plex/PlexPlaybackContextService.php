<?php

namespace App\Music\Plex;

use App\Models\Holding;
use App\Models\PlexItem;
use App\Models\PlexSyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class PlexPlaybackContextService
{
    /** @return array<string, mixed> */
    public function forReleaseGroup(string $releaseGroupId): array
    {
        $holdings = Holding::query()
            ->where('release_group_id', $releaseGroupId)
            ->whereHas('plexAlbum', fn ($query) => $query->where('item_type', 'album')->whereNull('removed_at')
                ->whereHas('library', fn ($libraries) => $libraries->where('section_uuid', (string) config('services.plex.expected_library_uuid'))
                    ->whereHas('server', fn ($servers) => $servers->where('machine_identifier', (string) config('services.plex.expected_machine_identifier')))))
            ->with('plexAlbum.library.server')
            ->get();
        if ($holdings->isEmpty()) {
            return $this->result('unavailable', 'active_holding', null, null, null, null);
        }

        $availabilityAsOf = PlexSyncRun::query()->where('status', 'completed')->latest('completed_at')->value('completed_at');
        $availabilityAsOf = $availabilityAsOf === null ? null : CarbonImmutable::parse($availabilityAsOf)->toAtomString();
        try {
            $cached = Cache::get(self::cacheKey());
        } catch (Throwable) {
            $cached = null;
        }
        $now = CarbonImmutable::now();
        try {
            $cacheIsFresh = is_array($cached) && isset($cached['expires_at']) && CarbonImmutable::parse($cached['expires_at'])->isAfter($now);
        } catch (Throwable) {
            $cacheIsFresh = false;
        }
        if ($cacheIsFresh) {
            $active = $cached['albums'][$releaseGroupId] ?? null;
            if (is_array($active) && in_array($active['state'] ?? null, ['playing', 'paused', 'buffering'], true)) {
                return $this->result('currently_active', 'active_session', $active['state'], $cached['observed_at'], null, $cached['expires_at'], $availabilityAsOf);
            }
        }

        $albumItems = $holdings->pluck('plexAlbum');
        $lastPlayed = $albumItems->pluck('last_viewed_at')->filter();
        foreach ($albumItems->groupBy('plex_library_id') as $libraryId => $albums) {
            $lastPlayed = $lastPlayed->merge(PlexItem::query()
                ->where('plex_library_id', $libraryId)->where('item_type', 'track')->whereNull('removed_at')
                ->whereIn('parent_rating_key', $albums->pluck('rating_key'))->whereNotNull('last_viewed_at')->pluck('last_viewed_at'));
        }
        $playedAt = $lastPlayed->map(fn ($value) => CarbonImmutable::parse($value))->filter(fn (CarbonImmutable $value) => ! $value->isFuture())->max();
        $recentDays = (int) config('services.plex.playback_recent_days', 90);
        if ($playedAt instanceof CarbonImmutable && $playedAt->addDays($recentDays)->isAfter($now)) {
            return $this->result('recently_played', 'plex_last_viewed', null, null, $playedAt->toAtomString(), $playedAt->addDays($recentDays)->toAtomString(), $availabilityAsOf);
        }

        return $this->result('available', 'active_holding', null, null, null, null, $availabilityAsOf);
    }

    public static function cacheKey(): string
    {
        return 'disco:plex-playback:'.hash('sha256', (string) config('services.plex.expected_machine_identifier')).':'.(string) config('services.plex.expected_library_uuid');
    }

    /** @return array<string, mixed> */
    private function result(string $status, string $basis, ?string $playerState, ?string $observedAt, ?string $lastPlayedAt, ?string $expiresAt, ?string $availabilityAsOf = null): array
    {
        return [
            'status' => $status,
            'basis' => $basis,
            'player_state' => $playerState,
            'observed_at' => $observedAt,
            'last_played_at' => $lastPlayedAt,
            'expires_at' => $expiresAt,
            'availability_as_of' => $availabilityAsOf,
        ];
    }
}
