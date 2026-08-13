<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\AlbumPresenter;
use App\Models\AlbumListItem;
use App\Models\CatalogEntity;
use App\Models\PlexEntityMatch;
use App\Models\PlexItem;
use App\Music\Library\AlbumFactsService;
use App\Music\Personal\AlbumListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlbumListController extends Controller
{
    public function __invoke(Request $request, AlbumPresenter $presenter, AlbumFactsService $factsService, AlbumListService $lists): JsonResponse
    {
        $validated = $request->validate([
            'page.number' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'page.size' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'in:want_to_listen,listened,removed,all'],
            'ownership' => ['sometimes', 'in:all,owned,outside'],
            'sort' => ['sometimes', 'in:-changed,changed,name,-name'],
        ]);
        $userId = (string) $request->user()->id;
        $lists->normalize($userId);
        $page = (int) data_get($validated, 'page.number', 1);
        $pageSize = (int) data_get($validated, 'page.size', 24);
        $status = $validated['status'] ?? 'want_to_listen';
        $ownership = $validated['ownership'] ?? 'all';
        $sort = $validated['sort'] ?? 'name';
        $ownershipConstraint = function ($query, string $filter): void {
            $relation = fn ($matches) => $matches->where('match_scope', 'release_group')->whereIn('status', ['confirmed', 'candidate'])
                ->whereHas('item', fn ($items) => $items->where('item_type', 'album')->whereNull('removed_at'));
            if ($filter === 'owned') {
                $query->whereHas('album.plexMatches', $relation);
            } elseif ($filter === 'outside') {
                $query->whereDoesntHave('album.plexMatches', $relation);
            }
        };
        $base = AlbumListItem::query()->where('discovery.album_list_items.user_id', $userId);
        $statusCounts = [
            'all' => tap((clone $base)->whereIn('discovery.album_list_items.status', ['want_to_listen', 'listened']), fn ($query) => $ownershipConstraint($query, $ownership))->count(),
            'want_to_listen' => tap((clone $base)->where('discovery.album_list_items.status', 'want_to_listen'), fn ($query) => $ownershipConstraint($query, $ownership))->count(),
            'listened' => tap((clone $base)->where('discovery.album_list_items.status', 'listened'), fn ($query) => $ownershipConstraint($query, $ownership))->count(),
            'removed' => tap((clone $base)->where('discovery.album_list_items.status', 'removed'), fn ($query) => $ownershipConstraint($query, $ownership))->count(),
        ];
        $query = clone $base;
        $status === 'all' ? $query->whereIn('discovery.album_list_items.status', ['want_to_listen', 'listened']) : $query->where('discovery.album_list_items.status', $status);
        $ownershipBase = clone $query;
        $ownershipCounts = [
            'all' => (clone $ownershipBase)->count(),
            'owned' => tap(clone $ownershipBase, fn ($query) => $ownershipConstraint($query, 'owned'))->count(),
            'outside' => tap(clone $ownershipBase, fn ($query) => $ownershipConstraint($query, 'outside'))->count(),
        ];
        $ownershipConstraint($query, $ownership);
        if (in_array($sort, ['name', '-name'], true)) {
            $query->join('catalog.entities', 'catalog.entities.id', '=', 'discovery.album_list_items.release_group_entity_id')
                ->select('discovery.album_list_items.*')->orderBy('catalog.entities.sort_name', $sort === 'name' ? 'asc' : 'desc');
        } else {
            $query->orderBy('state_changed_at', $sort === '-changed' ? 'desc' : 'asc');
        }
        $query->orderBy('release_group_entity_id');
        $lastPage = max(1, (int) ceil((clone $query)->count() / $pageSize));
        $page = min($page, $lastPage);
        $paginator = $query->paginate(perPage: $pageSize, pageName: 'page[number]', page: $page)->appends($request->query());
        $items = $paginator->getCollection();
        $ids = $items->pluck('release_group_entity_id');
        $entities = CatalogEntity::query()->whereIn('id', $ids)->with(['metadata', 'artwork'])->get()->keyBy('id');
        $matches = PlexEntityMatch::query()->whereIn('entity_id', $ids)->where('match_scope', 'release_group')->whereIn('status', ['confirmed', 'candidate'])
            ->whereHas('item', fn ($query) => $query->where('item_type', 'album')->whereNull('removed_at'))
            ->with(['item.artwork', 'item.guids', 'item.matches.entity.metadata'])->get()->groupBy('entity_id')
            ->map(fn ($group) => $group->sortBy(fn (PlexEntityMatch $match): string => ($match->status === 'confirmed' ? '0' : '1').$match->id)->first());
        $albums = $matches->pluck('item')->filter()->values();
        $artistKeys = $albums->pluck('parent_rating_key')->filter()->unique();
        $artists = PlexItem::query()->whereIn('rating_key', $artistKeys)->where('item_type', 'artist')->whereNull('removed_at')->with(['artwork', 'matches.entity.metadata'])->get()->keyBy(fn (PlexItem $artist): string => "{$artist->plex_library_id}:{$artist->rating_key}");
        $facts = $factsService->forAlbums($albums);
        $data = $items->map(function (AlbumListItem $item) use ($artists, $entities, $facts, $lists, $matches, $presenter): array {
            $match = $matches->get($item->release_group_entity_id);
            if ($match !== null) {
                $album = $match->item;
                $artist = $artists->get("{$album->plex_library_id}:{$album->parent_rating_key}");
                $summary = $presenter->summary($album, $artist, $facts["{$album->plex_library_id}:{$album->rating_key}"] ?? []);
            } else {
                $summary = $presenter->external($entities->get($item->release_group_entity_id));
            }
            $summary['list_state'] = $lists->present($item);

            return $summary;
        })->values();

        return response()->json(['data' => $data, 'meta' => [
            'current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(),
            'filter' => $status, 'ownership' => $ownership, 'sort' => $sort, 'filters' => $statusCounts, 'ownership_filters' => $ownershipCounts,
        ], 'links' => ['first' => $paginator->url(1), 'prev' => $paginator->previousPageUrl(), 'next' => $paginator->nextPageUrl(), 'last' => $paginator->url($paginator->lastPage())]]);
    }
}
