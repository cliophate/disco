<?php

namespace App\Http\Presenters;

use App\Models\CatalogEntity;
use App\Models\PlexEntityMatch;
use Illuminate\Support\Collection;

class CatalogPortraitPresenter
{
    public function __construct(private readonly ArtworkPresenter $artwork) {}

    /** @param Collection<int, string>|array<int, string> $entityIds
     * @return array<string, array<string, int|string>>
     */
    public function forEntities(Collection|array $entityIds): array
    {
        $ids = collect($entityIds)->filter()->unique()->values();
        $entities = CatalogEntity::query()->whereIn('id', $ids)->with('artwork')->get()->keyBy('id');
        $matches = PlexEntityMatch::query()
            ->whereIn('entity_id', $ids)
            ->where('match_scope', 'agent')
            ->whereIn('status', ['confirmed', 'candidate'])
            ->whereHas('item', fn ($query) => $query->where('item_type', 'artist')->whereNull('removed_at'))
            ->with('item.artwork')
            ->orderByRaw("case when status = 'confirmed' then 0 else 1 end")
            ->orderBy('id')
            ->get()
            ->unique('entity_id')
            ->keyBy('entity_id');

        return $ids->mapWithKeys(function (string $id) use ($entities, $matches): array {
            $portrait = ($entities->get($id) === null ? null : $this->artwork->forEntity($entities->get($id)))
                ?? $this->artwork->for($matches->get($id)?->item);

            return $portrait === null ? [] : [$id => $portrait];
        })->all();
    }
}
