<?php

namespace App\Music\Library;

use App\Models\Holding;
use App\Models\PlayAggregate;
use App\Models\PlexItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AlbumFactsService
{
    /** @param Collection<int, PlexItem> $albums
     * @return array<string, array<string, mixed>>
     */
    public function forAlbums(Collection $albums): array
    {
        if ($albums->isEmpty()) {
            return [];
        }

        $pairs = $albums
            ->map(fn (PlexItem $album): array => [$album->plex_library_id, $album->rating_key])
            ->unique(fn (array $pair): string => $pair[0].':'.$pair[1]);
        $tracks = PlexItem::query()
            ->selectRaw('plex_library_id, parent_rating_key, count(*) as track_count, coalesce(sum(duration_ms), 0) as duration_ms, count(*) filter (where coalesce(view_count, 0) > 0 or last_viewed_at is not null) as played_track_count, max(last_viewed_at) as last_heard_at')
            ->where('item_type', 'track')
            ->whereNull('removed_at')
            ->where(function ($query) use ($pairs): void {
                foreach ($pairs as [$libraryId, $ratingKey]) {
                    $query->orWhere(fn ($pair) => $pair
                        ->where('plex_library_id', $libraryId)
                        ->where('parent_rating_key', $ratingKey));
                }
            })
            ->groupBy('plex_library_id', 'parent_rating_key')
            ->get()
            ->keyBy(fn (PlexItem $track): string => "{$track->plex_library_id}:{$track->parent_rating_key}");
        $albumEntities = $albums->mapWithKeys(function (PlexItem $album): array {
            $matches = $album->relationLoaded('matches') ? $album->matches : $album->matches()->get();
            $match = $matches->where('match_scope', 'release_group')->firstWhere('status', 'confirmed')
                ?? $matches->where('match_scope', 'release_group')->firstWhere('status', 'candidate');

            return $match === null ? [] : ["{$album->plex_library_id}:{$album->rating_key}" => $match->entity_id];
        });
        $listenBrainz = PlayAggregate::query()
            ->whereIn('release_group_entity_id', $albumEntities->values()->unique())
            ->get()
            ->keyBy('release_group_entity_id');
        $holdingCounts = Holding::query()
            ->selectRaw('release_group_id, count(*) as holding_count')
            ->whereIn('release_group_id', $albumEntities->values()->unique())
            ->whereHas('plexAlbum', fn ($query) => $query->whereNull('removed_at')->where('item_type', 'album'))
            ->groupBy('release_group_id')
            ->pluck('holding_count', 'release_group_id');

        $facts = [];
        foreach ($albums as $album) {
            $key = "{$album->plex_library_id}:{$album->rating_key}";
            $track = $tracks->get($key);
            $albumLastHeard = $album->last_viewed_at === null ? null : CarbonImmutable::parse($album->last_viewed_at);
            $trackLastHeard = $track?->last_heard_at === null ? null : CarbonImmutable::parse($track->last_heard_at);
            $plexLastHeard = collect([$albumLastHeard, $trackLastHeard])->filter()->sort()->last();
            $albumPlayCount = (int) ($album->view_count ?? 0);
            $playedTrackCount = (int) ($track?->played_track_count ?? 0);
            $aggregate = $listenBrainz->get($albumEntities->get($key));
            $listenBrainzCount = (int) ($aggregate?->play_count ?? 0);
            $listenBrainzLastHeard = $aggregate?->last_listened_at === null
                ? null
                : CarbonImmutable::parse($aggregate->last_listened_at);
            $lastHeard = collect([$plexLastHeard, $listenBrainzLastHeard])->filter()->sort()->last();
            $lastHeardSource = match (true) {
                $lastHeard === null => null,
                $listenBrainzLastHeard !== null && $listenBrainzLastHeard->equalTo($lastHeard) => 'listenbrainz',
                default => 'plex',
            };
            $hasPlexSignal = $albumPlayCount > 0 || $playedTrackCount > 0 || $plexLastHeard !== null;
            $hasListenBrainzSignal = $listenBrainzCount > 0;
            $hasPlaySignal = $hasPlexSignal || $hasListenBrainzSignal;
            $facts[$key] = [
                'duration_ms' => (int) ($track?->duration_ms ?? 0),
                'track_count' => (int) ($track?->track_count ?? 0),
                'last_heard_at' => $lastHeard?->toAtomString(),
                'last_heard_source' => $lastHeardSource,
                'play_count' => $hasListenBrainzSignal ? $listenBrainzCount : $albumPlayCount,
                'played_track_count' => $playedTrackCount,
                'has_play_signal' => $hasPlaySignal,
                'holding_count' => (int) ($holdingCounts->get($albumEntities->get($key)) ?? 0),
                'signal_sources' => array_values(array_filter([
                    $hasPlexSignal ? 'plex' : null,
                    $hasListenBrainzSignal ? 'listenbrainz' : null,
                ])),
                'plex' => [
                    'album_view_count' => $albumPlayCount,
                    'played_track_count' => $playedTrackCount,
                    'last_viewed_at' => $plexLastHeard?->toAtomString(),
                ],
                'listenbrainz' => [
                    'listen_count' => $listenBrainzCount,
                    'first_listened_at' => $aggregate?->first_listened_at?->toAtomString(),
                    'last_listened_at' => $listenBrainzLastHeard?->toAtomString(),
                ],
            ];
        }

        return $facts;
    }
}
