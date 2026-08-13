<?php

namespace App\Music\Activity;

use App\Http\Presenters\AlbumPresenter;
use App\Models\Holding;
use App\Models\ListenImportRun;
use App\Models\PlayAggregate;
use App\Models\PlexItem;
use App\Models\PlexSyncRun;
use App\Models\SourceAccount;
use App\Music\Library\AlbumFactsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RecentCollectionActivityService
{
    private const MAX_EVENTS = 10;

    private const PLEX_FRESH_HOURS = 3;

    private const LISTENBRAINZ_FRESH_HOURS = 1;

    public function __construct(
        private readonly AlbumPresenter $albums,
        private readonly AlbumFactsService $facts,
    ) {}

    /** @return array{data:list<array<string,mixed>>,meta:array<string,mixed>} */
    public function forUser(string $userId): array
    {
        $listenBrainzAccount = SourceAccount::query()
            ->where('owner_user_id', $userId)
            ->where('status', 'active')
            ->whereHas('provider', fn ($query) => $query->where('slug', 'listenbrainz')->where('enabled', true))
            ->first();
        $playedAsOf = $listenBrainzAccount === null ? null : ListenImportRun::query()
            ->where('source_account_id', $listenBrainzAccount->id)
            ->where('status', 'completed')
            ->latest('completed_at')
            ->value('completed_at');

        $eligible = DB::table('library.holdings as holdings')
            ->join('library.plex_items as albums', 'albums.id', '=', 'holdings.plex_album_item_id')
            ->join('library.plex_entity_matches as matches', function ($join): void {
                $join->on('matches.plex_item_id', '=', 'albums.id')
                    ->on('matches.entity_id', '=', 'holdings.release_group_id');
            })
            ->join('catalog.entities as entities', 'entities.id', '=', 'holdings.release_group_id')
            ->where('albums.item_type', 'album')->whereNull('albums.removed_at')
            ->where('matches.match_scope', 'release_group')->where('matches.status', 'confirmed')
            ->where('entities.kind', 'release_group')->where('entities.status', 'active');
        $addedCandidates = (clone $eligible)
            ->whereNotNull('albums.added_at_plex')
            ->selectRaw('holdings.release_group_id, min(albums.added_at_plex) as occurred_at')
            ->groupBy('holdings.release_group_id')
            ->orderByDesc('occurred_at')->limit(self::MAX_EVENTS)->get();
        $playedCandidates = (clone $eligible)
            ->join('activity.play_aggregates as plays', 'plays.release_group_entity_id', '=', 'holdings.release_group_id')
            ->whereNotNull('plays.last_listened_at')
            ->selectRaw('holdings.release_group_id, max(plays.last_listened_at) as occurred_at')
            ->groupBy('holdings.release_group_id')
            ->orderByDesc('occurred_at')->limit(self::MAX_EVENTS)->get();
        $candidateIds = $addedCandidates->pluck('release_group_id')->merge($playedCandidates->pluck('release_group_id'))->unique()->values();
        $libraryIds = (clone $eligible)->distinct()->pluck('albums.plex_library_id');
        $addedAsOf = PlexSyncRun::query()
            ->whereIn('plex_library_id', $libraryIds)
            ->where('status', 'completed')
            ->selectRaw('plex_library_id, max(completed_at) as completed_at')
            ->groupBy('plex_library_id')
            ->get()->min('completed_at');

        $holdings = Holding::query()
            ->whereIn('release_group_id', $candidateIds)
            ->whereHas('releaseGroup.entity', fn ($query) => $query
                ->where('kind', 'release_group')
                ->where('status', 'active'))
            ->whereHas('plexAlbum', fn ($query) => $query
                ->where('item_type', 'album')
                ->whereNull('removed_at'))
            ->with([
                'releaseGroup.entity',
                'plexAlbum.artwork',
                'plexAlbum.guids',
                'plexAlbum.matches.entity.metadata',
                'plexAlbum.matches.entity.release',
            ])
            ->get()
            ->filter(fn (Holding $holding): bool => $holding->plexAlbum->matches->contains(
                fn ($match): bool => $match->match_scope === 'release_group'
                    && $match->status === 'confirmed'
                    && $match->entity_id === $holding->release_group_id
                    && $match->entity?->status === 'active',
            ));

        $byReleaseGroup = $holdings->groupBy('release_group_id');
        $representativeAlbums = $byReleaseGroup->map(function (Collection $copies): PlexItem {
            return $copies
                ->sort(fn (Holding $left, Holding $right): int => ((int) $right->is_primary_playback_copy <=> (int) $left->is_primary_playback_copy)
                    ?: strcmp((string) $left->id, (string) $right->id))
                ->first()
                ->plexAlbum;
        });
        $albumFacts = $this->facts->forAlbums($representativeAlbums->values());
        $artists = PlexItem::query()
            ->where('item_type', 'artist')
            ->whereNull('removed_at')
            ->whereIn('rating_key', $representativeAlbums->pluck('parent_rating_key')->filter()->unique())
            ->whereHas('matches', fn ($query) => $query
                ->where('match_scope', 'agent')
                ->where('status', 'confirmed')
                ->whereHas('entity', fn ($entity) => $entity
                    ->where('kind', 'agent')
                    ->where('status', 'active')))
            ->with(['artwork', 'matches.entity.metadata'])
            ->get()
            ->keyBy(fn (PlexItem $artist): string => "{$artist->plex_library_id}:{$artist->rating_key}");
        $aggregates = PlayAggregate::query()
            ->whereIn('release_group_entity_id', $byReleaseGroup->keys())
            ->get()
            ->keyBy('release_group_entity_id');

        $events = $byReleaseGroup->flatMap(function (Collection $copies, string $releaseGroupId) use ($aggregates, $albumFacts, $artists, $representativeAlbums): array {
            $album = $representativeAlbums->get($releaseGroupId);
            $artist = $artists->get("{$album->plex_library_id}:{$album->parent_rating_key}");
            $summary = $this->albums->summary(
                $album,
                $artist,
                $albumFacts["{$album->plex_library_id}:{$album->rating_key}"] ?? [],
            );
            $events = [];
            $aggregate = $aggregates->get($releaseGroupId);
            if ($aggregate?->last_listened_at !== null) {
                $events[] = [
                    'id' => "played:{$releaseGroupId}",
                    'kind' => 'played',
                    'occurred_at' => $aggregate->last_listened_at->toAtomString(),
                    'album' => $summary,
                ];
            }
            $addedAt = $copies->pluck('plexAlbum.added_at_plex')->filter()->min();
            if ($addedAt !== null) {
                $events[] = [
                    'id' => "added:{$releaseGroupId}",
                    'kind' => 'added',
                    'occurred_at' => CarbonImmutable::parse($addedAt)->toAtomString(),
                    'album' => $summary,
                ];
            }

            return $events;
        })->sort(function (array $left, array $right): int {
            return strcmp($right['occurred_at'], $left['occurred_at']) ?: strcmp($left['id'], $right['id']);
        })->take(self::MAX_EVENTS)->values();

        $now = CarbonImmutable::now();
        $plexStale = $addedAsOf === null || CarbonImmutable::parse($addedAsOf)->lt($now->subHours(self::PLEX_FRESH_HOURS));
        $listenBrainzStale = $listenBrainzAccount !== null
            && ($playedAsOf === null || CarbonImmutable::parse($playedAsOf)->lt($now->subHours(self::LISTENBRAINZ_FRESH_HOURS)));
        $stale = $plexStale || $listenBrainzStale;

        return [
            'data' => $events->all(),
            'meta' => [
                'status' => $events->isEmpty() ? 'empty' : ($stale ? 'stale' : 'ready'),
                'stale' => $stale,
                'added_as_of' => $addedAsOf === null ? null : CarbonImmutable::parse($addedAsOf)->toAtomString(),
                'played_as_of' => $playedAsOf === null ? null : CarbonImmutable::parse($playedAsOf)->toAtomString(),
            ],
        ];
    }
}
