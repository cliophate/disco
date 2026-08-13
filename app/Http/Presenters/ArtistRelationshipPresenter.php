<?php

namespace App\Http\Presenters;

use App\Models\CreditEdge;
use Illuminate\Support\Collection;

class ArtistRelationshipPresenter
{
    private const ROLE_ORDER = ['performer', 'producer', 'songwriter', 'engineer', 'other'];

    public function __construct(private readonly CatalogPortraitPresenter $portraits) {}

    /** @return array{status:string,roles:list<string>,people:list<array<string,mixed>>,works:list<array<string,mixed>>} */
    public function present(string $artistId): array
    {
        $artistEdges = CreditEdge::query()
            ->where('target_entity_id', $artistId)
            ->with(['subject', 'snapshot.object.provider'])
            ->orderBy('position')
            ->get();
        if ($artistEdges->isEmpty()) {
            return ['status' => 'unavailable', 'roles' => [], 'people' => [], 'works' => []];
        }
        $subjectIds = $artistEdges->pluck('subject_entity_id')->unique();
        $sharedEdges = CreditEdge::query()
            ->whereIn('subject_entity_id', $subjectIds)
            ->where('target_entity_id', '!=', $artistId)
            ->whereHas('target', fn ($query) => $query->where('status', 'active'))
            ->with(['subject', 'target', 'snapshot.object.provider'])
            ->orderBy('position')
            ->get();
        $peopleEdges = $sharedEdges->filter(fn (CreditEdge $edge): bool => $edge->target?->kind === 'agent');
        $portraits = $this->portraits->forEntities($peopleEdges->pluck('target_entity_id'));
        $people = $peopleEdges->groupBy('target_entity_id')->map(function (Collection $edges, string $id) use ($portraits): array {
            $target = $edges->first()->target;

            return [
                'id' => $id,
                'name' => $target->canonical_name,
                'portrait' => $portraits[$id] ?? null,
                'roles' => $this->orderedRoles($edges->pluck('role')),
                'shared_credits' => $edges->pluck('subject_entity_id')->unique()->count(),
            ];
        })->sortBy(fn (array $person): string => sprintf('%04d:%s', 9999 - $person['shared_credits'], mb_strtolower($person['name'])))->take(12)->values()->all();

        $directWorks = $artistEdges->filter(fn (CreditEdge $edge): bool => $edge->subject?->kind === 'work')->map(fn (CreditEdge $edge): array => [
            'id' => $edge->subject->id,
            'name' => $edge->subject->canonical_name,
            'relationship_type' => $edge->attributes['relationship_type'] ?? $edge->role,
        ]);
        $relatedWorks = $sharedEdges->filter(fn (CreditEdge $edge): bool => $edge->target?->kind === 'work')->map(fn (CreditEdge $edge): array => [
            'id' => $edge->target->id,
            'name' => $edge->credited_name ?: $edge->target->canonical_name,
            'relationship_type' => $edge->attributes['relationship_type'] ?? $edge->role,
        ]);
        $works = $directWorks->concat($relatedWorks)->unique('id')->take(12)->values()->all();
        $roles = $this->orderedRoles($artistEdges->pluck('role'));

        return ['status' => $people === [] && $works === [] ? 'unavailable' : 'available', 'roles' => $roles, 'people' => $people, 'works' => $works];
    }

    /** @return list<string> */
    private function orderedRoles(Collection $roles): array
    {
        return collect(self::ROLE_ORDER)->filter(fn (string $role): bool => $roles->contains($role))->values()->all();
    }
}
