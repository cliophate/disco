<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\ArtistNamePresenter;
use App\Http\Presenters\ArtworkPresenter;
use App\Models\CatalogEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArtistIndexController extends Controller
{
    public function __invoke(Request $request, ArtworkPresenter $artworkPresenter, ArtistNamePresenter $artistNames): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'size' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'type' => ['sometimes', 'in:all,person,group,other'],
            'sort' => ['sometimes', 'in:name,-name'],
        ]);
        $page = (int) ($validated['page'] ?? 1);
        $pageSize = (int) ($validated['size'] ?? 24);
        $filter = (string) ($validated['type'] ?? 'all');
        $sort = (string) ($validated['sort'] ?? 'name');
        $base = $this->ownedArtists();
        $total = (clone $base)->count();
        $people = (clone $base)->whereHas('metadata', fn (Builder $query) => $query->whereRaw('lower(primary_type) = ?', ['person']))->count();
        $groups = (clone $base)->whereHas('metadata', fn (Builder $query) => $query->whereRaw('lower(primary_type) in (?, ?, ?)', ['group', 'orchestra', 'choir']))->count();

        $query = $this->applyFilter($base, $filter)
            ->with([
                'metadata',
                'plexMatches' => fn ($matches) => $matches
                    ->where('match_scope', 'agent')
                    ->whereIn('status', ['confirmed', 'candidate'])
                    ->whereHas('item', fn ($items) => $items->where('item_type', 'artist')->whereNull('removed_at'))
                    ->with('item.artwork')
                    ->orderByRaw("case when status = 'confirmed' then 0 else 1 end")
                    ->orderBy('id'),
            ]);
        $direction = $sort === '-name' ? 'desc' : 'asc';
        $paginator = $query
            ->orderByRaw("lower(sort_name) {$direction}")
            ->orderBy('id')
            ->paginate(perPage: $pageSize, pageName: 'page', page: $page);
        $paginator->appends($request->query());

        return response()->json([
            'data' => $paginator->getCollection()->map(function (CatalogEntity $entity) use ($artistNames, $artworkPresenter): array {
                $item = $entity->plexMatches->first()?->item;

                return [
                    'id' => $entity->id,
                    ...$artistNames->present($entity->canonical_name, $entity->metadata?->primary_type, $entity->metadata?->disambiguation),
                    'portrait' => $item === null ? null : $artworkPresenter->for($item),
                    'type' => $entity->metadata?->primary_type,
                    'area' => $entity->metadata?->area,
                    'genres' => collect($entity->metadata?->genres ?? [])->pluck('name')->take(8)->values(),
                ];
            })->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'filters' => [
                    'all' => $total,
                    'person' => $people,
                    'group' => $groups,
                    'other' => max(0, $total - $people - $groups),
                ],
                'sort' => $sort,
                'filter' => $filter,
            ],
            'links' => [
                'first' => $paginator->url(1),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
                'last' => $paginator->url($paginator->lastPage()),
            ],
        ]);
    }

    private function ownedArtists(): Builder
    {
        return CatalogEntity::query()
            ->where('kind', 'agent')
            ->whereHas('plexMatches', fn (Builder $query) => $query
                ->where('match_scope', 'agent')
                ->whereIn('status', ['confirmed', 'candidate'])
                ->whereHas('item', fn (Builder $item) => $item->where('item_type', 'artist')->whereNull('removed_at')));
    }

    private function applyFilter(Builder $query, string $filter): Builder
    {
        return match ($filter) {
            'person' => $query->whereHas('metadata', fn (Builder $metadata) => $metadata->whereRaw('lower(primary_type) = ?', ['person'])),
            'group' => $query->whereHas('metadata', fn (Builder $metadata) => $metadata->whereRaw('lower(primary_type) in (?, ?, ?)', ['group', 'orchestra', 'choir'])),
            'other' => $query->whereDoesntHave('metadata', fn (Builder $metadata) => $metadata->whereRaw('lower(primary_type) in (?, ?, ?, ?)', ['person', 'group', 'orchestra', 'choir'])),
            default => $query,
        };
    }
}
