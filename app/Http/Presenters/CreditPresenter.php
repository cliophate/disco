<?php

namespace App\Http\Presenters;

use App\Models\CreditEdge;
use Illuminate\Support\Collection;

class CreditPresenter
{
    private const ROLE_ORDER = ['performer', 'producer', 'songwriter', 'engineer', 'work', 'other'];

    public function __construct(private readonly CatalogPortraitPresenter $portraits) {}

    /** @param list<string> $subjectIds
     * @return array<string, array{status:string,groups:list<array{role:string,label:string,items:list<array<string,mixed>>}>}>
     */
    public function forSubjects(array $subjectIds): array
    {
        $subjectIds = array_values(array_unique(array_filter($subjectIds)));
        $edges = CreditEdge::query()
            ->whereIn('subject_entity_id', $subjectIds)
            ->with(['target', 'snapshot.object.provider'])
            ->orderBy('position')
            ->get();
        $workIds = $edges->where('role', 'work')->pluck('target_entity_id')->filter()->unique()->values();
        $workEdges = CreditEdge::query()
            ->whereIn('subject_entity_id', $workIds)
            ->with(['target', 'snapshot.object.provider'])
            ->orderBy('position')
            ->get()
            ->groupBy('subject_entity_id');
        $portraitMap = $this->portraits->forEntities($edges->concat($workEdges->flatten())->pluck('target_entity_id'));
        $result = [];
        foreach ($subjectIds as $subjectId) {
            $subjectEdges = $edges->where('subject_entity_id', $subjectId)->values();
            $viaWorks = $subjectEdges->where('role', 'work')->flatMap(function (CreditEdge $work) use ($portraitMap, $workEdges): Collection {
                return collect($workEdges->get($work->target_entity_id, []))->map(function (CreditEdge $edge) use ($portraitMap, $work): array {
                    $item = $this->item($edge, $portraitMap);
                    $item['via_work'] = $work->target === null ? null : ['id' => $work->target->id, 'name' => $work->target->canonical_name];

                    return ['role' => $edge->role, 'item' => $item];
                });
            });
            $presented = $subjectEdges->map(fn (CreditEdge $edge): array => ['role' => $edge->role, 'item' => $this->item($edge, $portraitMap)])->concat($viaWorks);
            $groups = collect(self::ROLE_ORDER)->map(function (string $role) use ($presented): ?array {
                $items = $presented->where('role', $role)->pluck('item')->values()->all();

                return $items === [] ? null : ['role' => $role, 'label' => $this->label($role), 'items' => $items];
            })->filter()->values()->all();
            $result[$subjectId] = ['status' => $groups === [] ? 'unavailable' : 'available', 'groups' => $groups];
        }

        return $result;
    }

    /** @param array<string, array{status:string,groups:list<array{role:string,label:string,items:list<array<string,mixed>>}>}> $credits
     * @param  list<?string>  $subjectIds
     * @return array{status:string,groups:list<array{role:string,label:string,items:list<array<string,mixed>>}>}
     */
    public function combine(array $credits, array $subjectIds): array
    {
        $groups = collect($subjectIds)->filter()->flatMap(fn (string $id): array => $credits[$id]['groups'] ?? [])->groupBy('role');
        $combined = collect(self::ROLE_ORDER)->map(function (string $role) use ($groups): ?array {
            $roleGroups = $groups->get($role);
            if ($roleGroups === null) {
                return null;
            }

            $items = $roleGroups->flatMap(fn (array $group): array => $group['items'])
                ->unique(fn (array $item): string => implode('|', [$item['target']['id'] ?? $item['name'], $item['relationship_type'], $item['via_work']['id'] ?? '']))
                ->values()
                ->all();

            return ['role' => $role, 'label' => $this->label($role), 'items' => $items];
        })->filter()->values()->all();

        return ['status' => $combined === [] ? 'unavailable' : 'available', 'groups' => $combined];
    }

    /** @return array<string, mixed> */
    private function item(CreditEdge $edge, array $portraits): array
    {
        $target = $edge->target?->status === 'active' ? $edge->target : null;
        $name = is_string($edge->credited_name) && trim($edge->credited_name) !== ''
            ? trim($edge->credited_name)
            : ($target?->canonical_name ?? 'Unknown credit');

        return [
            'name' => $name,
            'target' => $target === null ? null : ['id' => $target->id, 'kind' => $target->kind, 'name' => $target->canonical_name],
            'portrait' => $target === null ? null : ($portraits[$target->id] ?? null),
            'relationship_type' => $edge->attributes['relationship_type'] ?? $edge->role,
            'attributes' => $edge->attributes['attributes'] ?? [],
            'provenance' => [
                'provider' => $edge->snapshot?->object?->provider?->display_name ?? 'MusicBrainz',
                'url' => $edge->snapshot?->object?->canonical_url,
                'retrieved_at' => $edge->snapshot?->retrieved_at?->toIso8601String(),
            ],
        ];
    }

    private function label(string $role): string
    {
        return match ($role) {
            'performer' => 'Performers',
            'producer' => 'Production',
            'songwriter' => 'Songwriting',
            'engineer' => 'Engineering',
            'work' => 'Works',
            default => 'Other credits',
        };
    }
}
