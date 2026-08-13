<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\AlbumListStatePresenter;
use App\Http\Presenters\AlbumPresenter;
use App\Http\Presenters\ArtistNamePresenter;
use App\Http\Presenters\ArtworkPresenter;
use App\Models\CatalogEntity;
use App\Models\PlexEntityMatch;
use App\Models\PlexItem;
use App\Music\Library\AlbumFactsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request, AlbumPresenter $presenter, AlbumListStatePresenter $listStates, ArtworkPresenter $artworkPresenter, ArtistNamePresenter $artistNames, AlbumFactsService $factsService): JsonResponse
    {
        $query = trim((string) $request->validate(['q' => ['nullable', 'string', 'max:120']])['q'] ?? '');
        if ($query === '') {
            return response()->json(['data' => ['albums' => [], 'artists' => []]]);
        }

        $entities = CatalogEntity::query()
            ->whereIn('kind', ['release_group', 'agent'])
            ->where(fn ($names) => $names
                ->where('canonical_name', 'ilike', '%'.$this->escapeLike($query).'%')
                ->orWhereHas('metadata', fn ($metadata) => $metadata
                    ->where('disambiguation', 'ilike', '%'.$this->escapeLike($query).'%')))
            ->where(function ($builder): void {
                $builder->where(function ($releaseGroups): void {
                    $releaseGroups->where('kind', 'release_group')
                        ->whereHas('plexMatches', fn ($matches) => $matches
                            ->where('match_scope', 'release_group')
                            ->whereIn('status', ['confirmed', 'candidate'])
                            ->whereHas('item', fn ($items) => $items
                                ->where('item_type', 'album')
                                ->whereNull('removed_at')));
                })->orWhere(function ($agents): void {
                    $agents->where('kind', 'agent')
                        ->whereHas('plexMatches', fn ($matches) => $matches
                            ->where('match_scope', 'agent')
                            ->whereIn('status', ['confirmed', 'candidate'])
                            ->whereHas('item', fn ($items) => $items
                                ->where('item_type', 'artist')
                                ->whereNull('removed_at')));
                });
            })
            ->orderByRaw('lower(canonical_name) = lower(?) desc', [$query])
            ->orderBy('sort_name')
            ->limit(101)
            ->with('metadata')
            ->get();
        $truncated = $entities->count() > 100;
        $entities = $entities->take(100);

        $releaseGroupIds = $entities->where('kind', 'release_group')->pluck('id');
        $agentIds = $entities->where('kind', 'agent')->pluck('id');
        $matches = PlexEntityMatch::query()
            ->where(function ($query) use ($agentIds, $releaseGroupIds): void {
                $query->where(fn ($matches) => $matches
                    ->where('match_scope', 'release_group')
                    ->whereIn('entity_id', $releaseGroupIds))
                    ->orWhere(fn ($matches) => $matches
                        ->where('match_scope', 'agent')
                        ->whereIn('entity_id', $agentIds));
            })
            ->whereIn('status', ['confirmed', 'candidate'])
            ->whereHas('item', fn ($query) => $query->whereNull('removed_at'))
            ->with(['item.artwork', 'item.guids', 'item.matches.entity.metadata'])
            ->get()
            ->groupBy('entity_id')
            ->map(fn ($group) => $group->sortBy(fn (PlexEntityMatch $match): string => ($match->status === 'confirmed' ? '0' : '1').$match->id)->first());

        $artistItems = $matches->pluck('item')
            ->filter(fn (?PlexItem $item): bool => $item?->item_type === 'artist')
            ->keyBy(fn (PlexItem $item): string => "{$item->plex_library_id}:{$item->rating_key}");

        $albumArtistKeys = $matches->pluck('item')
            ->filter(fn (?PlexItem $item): bool => $item?->item_type === 'album')
            ->map(fn (PlexItem $item): string => "{$item->plex_library_id}:{$item->parent_rating_key}");
        $missingArtistKeys = $albumArtistKeys->diff($artistItems->keys());
        if ($missingArtistKeys->isNotEmpty()) {
            $moreArtists = PlexItem::query()
                ->where('item_type', 'artist')
                ->whereNull('removed_at')
                ->whereHas('matches', fn ($query) => $query
                    ->where('match_scope', 'agent')
                    ->whereIn('status', ['confirmed', 'candidate']))
                ->where(function ($builder) use ($missingArtistKeys): void {
                    foreach ($missingArtistKeys as $compound) {
                        [$libraryId, $ratingKey] = explode(':', $compound, 2);
                        $builder->orWhere(fn ($query) => $query
                            ->where('plex_library_id', $libraryId)
                            ->where('rating_key', $ratingKey));
                    }
                })
                ->with(['artwork', 'matches.entity.metadata'])
                ->get()
                ->keyBy(fn (PlexItem $item): string => "{$item->plex_library_id}:{$item->rating_key}");
            $artistItems = $artistItems->union($moreArtists);
        }

        $albums = [];
        $artists = [];
        $albumItems = $matches->pluck('item')->filter(fn (?PlexItem $item): bool => $item?->item_type === 'album')->values();
        $facts = $factsService->forAlbums($albumItems);
        foreach ($entities as $entity) {
            $item = $matches->get($entity->id)?->item;
            if ($item?->item_type === 'album') {
                $artist = $artistItems->get("{$item->plex_library_id}:{$item->parent_rating_key}");
                $albums[] = $presenter->summary(
                    $item,
                    $artist,
                    $facts["{$item->plex_library_id}:{$item->rating_key}"] ?? [],
                );
            } elseif ($item?->item_type === 'artist') {
                $artists[] = [
                    'id' => $entity->id,
                    ...$artistNames->present($entity->canonical_name, $entity->metadata?->primary_type, $entity->metadata?->disambiguation),
                    'portrait' => $artworkPresenter->for($item),
                ];
            }
        }

        return response()->json([
            'data' => ['albums' => $listStates->overlay($albums, (string) $request->user()->id), 'artists' => $artists],
            'meta' => ['limit' => 100, 'truncated' => $truncated],
        ]);
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
