<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\AlbumListStatePresenter;
use App\Http\Presenters\AlbumPresenter;
use App\Models\CatalogEntity;
use App\Models\PlexItem;
use App\Music\Library\AlbumFactsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LibraryAlbumController extends Controller
{
    public function __invoke(Request $request, AlbumPresenter $presenter, AlbumListStatePresenter $listStates, AlbumFactsService $factsService): JsonResponse
    {
        $validated = $request->validate([
            'page.number' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'page.size' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'type' => ['sometimes', 'in:all,album,ep,single,other'],
            'sort' => ['sometimes', 'in:name,-name,newest,oldest'],
        ]);
        $page = (int) data_get($validated, 'page.number', 1);
        $pageSize = (int) data_get($validated, 'page.size', 24);
        $type = $validated['type'] ?? 'all';
        $sort = $validated['sort'] ?? 'name';
        $base = CatalogEntity::query()
            ->where('catalog.entities.status', 'active')
            ->where('kind', 'release_group')
            ->whereHas('plexMatches', fn ($query) => $query
                ->where('match_scope', 'release_group')
                ->whereIn('status', ['confirmed', 'candidate'])
                ->whereHas('item', fn ($item) => $item->whereNull('removed_at')->where('item_type', 'album')));
        $typeQuery = function ($query, string $value): void {
            if (in_array($value, ['album', 'ep', 'single'], true)) {
                $query->whereHas('releaseGroup', fn ($release) => $release->whereRaw('lower(primary_type) = ?', [$value]));
            } elseif ($value === 'other') {
                $query->where(fn ($other) => $other->whereDoesntHave('releaseGroup')->orWhereHas('releaseGroup', fn ($release) => $release->whereNull('primary_type')->orWhereRaw("lower(primary_type) not in ('album', 'ep', 'single')")));
            }
        };
        $filters = ['all' => (clone $base)->count()];
        foreach (['album', 'ep', 'single', 'other'] as $filter) {
            $query = clone $base;
            $typeQuery($query, $filter);
            $filters[$filter] = $query->count();
        }
        $typeQuery($base, $type);
        $page = min($page, max(1, (int) ceil((clone $base)->count() / $pageSize)));
        $query = $base
            ->with(['plexMatches' => fn ($query) => $query
                ->where('match_scope', 'release_group')
                ->whereIn('status', ['confirmed', 'candidate'])
                ->whereHas('item', fn ($item) => $item->whereNull('removed_at')->where('item_type', 'album'))
                ->with(['item.artwork', 'item.guids', 'item.matches.entity.metadata'])
                ->orderByRaw("case when status = 'confirmed' then 0 else 1 end")
                ->orderBy('id')]);
        if (in_array($sort, ['newest', 'oldest'], true)) {
            $query->leftJoin('catalog.release_groups', 'catalog.release_groups.entity_id', '=', 'catalog.entities.id')
                ->select('catalog.entities.*')
                ->orderByRaw('catalog.release_groups.first_release_year '.($sort === 'newest' ? 'desc' : 'asc').' nulls last');
        }
        $paginator = $query
            ->orderBy('catalog.entities.sort_name', $sort === '-name' ? 'desc' : 'asc')
            ->orderBy('catalog.entities.id')
            ->paginate(perPage: $pageSize, pageName: 'page[number]', page: $page);
        $paginator->appends($request->query());

        $albums = $paginator->getCollection()
            ->map(fn (CatalogEntity $entity): ?PlexItem => $entity->plexMatches->first()?->item)
            ->filter();
        $artistKeys = $albums->pluck('parent_rating_key')->filter()->unique();
        $artists = PlexItem::query()
            ->whereIn('rating_key', $artistKeys)
            ->where('item_type', 'artist')
            ->whereNull('removed_at')
            ->whereHas('matches', fn ($query) => $query
                ->where('match_scope', 'agent')
                ->whereIn('status', ['confirmed', 'candidate']))
            ->with(['artwork', 'matches.entity.metadata'])
            ->get()
            ->keyBy(fn (PlexItem $item): string => "{$item->plex_library_id}:{$item->rating_key}");
        $facts = $factsService->forAlbums($albums->values());

        $data = $albums->map(function (PlexItem $album) use ($artists, $facts, $presenter): array {
            $artist = $artists->get("{$album->plex_library_id}:{$album->parent_rating_key}");

            return $presenter->summary($album, $artist, $facts["{$album->plex_library_id}:{$album->rating_key}"] ?? []);
        })->values();

        return response()->json([
            'data' => $listStates->overlay($data->all(), (string) $request->user()->id),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'filters' => $filters,
                'filter' => $type,
                'sort' => $sort,
            ],
            'links' => [
                'first' => $paginator->url(1),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
                'last' => $paginator->url($paginator->lastPage()),
            ],
        ]);
    }
}
