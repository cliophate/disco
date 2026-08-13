<?php

namespace App\Music\Discovery;

use App\Http\Presenters\AlbumListStatePresenter;
use App\Http\Presenters\AlbumPresenter;
use App\Http\Presenters\ArtworkPresenter;
use App\Models\ArtistDiscographyGeneration;
use App\Models\ArtistDiscographyItem;
use App\Models\CatalogEntity;
use App\Models\Holding;
use App\Models\PlayAggregate;
use App\Models\RecommendationRun;
use App\Models\UpcomingReleaseGeneration;
use App\Music\CanonicalEntityResolver;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ArtistDiscographyService
{
    public function __construct(
        private readonly CanonicalEntityResolver $canonicalEntities,
        private readonly AlbumPresenter $albums,
        private readonly AlbumListStatePresenter $listStates,
        private readonly ArtworkPresenter $artwork,
    ) {}

    /** @return array{data:list<array<string,mixed>>,generation:?ArtistDiscographyGeneration,total:int,page:int,counts:array<string,array<string,int>>} */
    public function page(string $userId, string $artistId, string $view, string $types, string $noise, ?string $generationId, int $page, int $pageSize): array
    {
        $artist = $this->canonicalEntities->resolve($artistId, 'agent');
        if ($artist === null) {
            throw new NotFoundHttpException('Artist not found.');
        }
        $artistIds = $this->identityIds([$artist->id]);
        $generation = $generationId === null
            ? ArtistDiscographyGeneration::query()->whereIn('artist_entity_id', $artistIds)->latest('generated_at')->first()
            : ArtistDiscographyGeneration::query()->whereIn('artist_entity_id', $artistIds)->find($generationId);
        if ($generationId !== null && $generation === null) {
            throw new NotFoundHttpException('Artist discography generation not found.');
        }
        if ($generation === null) {
            return [
                'data' => [], 'generation' => null, 'total' => 0, 'page' => 1,
                'counts' => ['views' => ['missing' => 0, 'present' => 0, 'all' => 0], 'types' => ['albums' => 0, 'albums_eps' => 0, 'all' => 0]],
            ];
        }

        $items = ArtistDiscographyItem::query()
            ->where('generation_id', $generation->id)
            ->with(['releaseGroup.metadata', 'releaseGroup.artwork'])
            ->orderBy('position')
            ->get();
        $canonicalItems = $this->canonicalItems($items);
        $ids = $canonicalItems->pluck('canonical_id')->all();
        $sourceIds = $this->identityIds($canonicalItems->pluck('item.release_group_id')->merge($ids)->unique()->all());
        $holdings = $this->activeHoldings($sourceIds);
        $recommended = $this->latestRecommendations($userId, $sourceIds);
        $upcoming = $this->latestUpcoming($sourceIds);
        $listening = $this->observedListening($sourceIds);
        $excluded = collect(config('discovery.discography.excluded_secondary_types', []))
            ->map(fn (mixed $type): string => strtolower((string) $type))->flip();

        $rows = $canonicalItems->map(function (array $candidate) use ($holdings, $listening, $recommended, $upcoming): array {
            /** @var ArtistDiscographyItem $item */
            $item = $candidate['item'];
            $id = $candidate['canonical_id'];
            $entity = $candidate['entity'];
            $album = $this->albums->external($entity);
            $holding = $holdings[$id] ?? null;
            $observed = $listening[$id] ?? null;
            if ($holding !== null) {
                $album['owned'] = true;
                $album['plex_item_id'] = $holding['plex_item_id'];
                $album['open_in_plex_available'] = true;
                $album['open_in_plex_status'] = $holding['count'] > 1 ? 'choice-required' : 'exact';
                $album['artwork'] = $holding['artwork'] ?? $album['artwork'];
            }

            return [
                'id' => $id,
                'album' => $album,
                'primary_type' => strtolower((string) $item->primary_type),
                'secondary_types' => $item->secondary_types,
                'states' => [
                    'holding' => $holding === null ? 'absent' : 'present',
                    'recommended' => isset($recommended[$id]),
                    'upcoming' => isset($upcoming[$id]),
                    'observed_listening' => $observed !== null || ($holding['observed'] ?? false),
                    'last_listened_at' => $observed['last_listened_at'] ?? null,
                ],
                'official_release_evidence' => [
                    'status' => 'official',
                    'release_mbid' => $item->official_release_mbid,
                    'release_date' => $item->official_release_date?->toDateString(),
                ],
            ];
        });
        $typeEligible = fn (array $row, string $filter): bool => match ($filter) {
            'albums' => $row['primary_type'] === 'album',
            'albums_eps' => in_array($row['primary_type'], ['album', 'ep'], true),
            default => true,
        };
        $coreEligible = fn (array $row): bool => collect($row['secondary_types'])
            ->map(fn (mixed $type): string => strtolower((string) $type))
            ->doesntContain(fn (string $type): bool => $excluded->has($type));
        $viewEligible = fn (array $row, string $filter): bool => match ($filter) {
            'missing' => $row['states']['holding'] === 'absent',
            'present' => $row['states']['holding'] === 'present',
            default => true,
        };
        $forViews = $rows->filter(fn (array $row): bool => $typeEligible($row, $types) && ($noise === 'all' || $coreEligible($row)));
        $forTypes = $rows->filter(fn (array $row): bool => $viewEligible($row, $view) && ($noise === 'all' || $coreEligible($row)));
        $counts = [
            'views' => [
                'missing' => $forViews->filter(fn (array $row): bool => $viewEligible($row, 'missing'))->count(),
                'present' => $forViews->filter(fn (array $row): bool => $viewEligible($row, 'present'))->count(),
                'all' => $forViews->count(),
            ],
            'types' => [
                'albums' => $forTypes->filter(fn (array $row): bool => $typeEligible($row, 'albums'))->count(),
                'albums_eps' => $forTypes->filter(fn (array $row): bool => $typeEligible($row, 'albums_eps'))->count(),
                'all' => $forTypes->count(),
            ],
        ];
        $filtered = $rows->filter(fn (array $row): bool => $viewEligible($row, $view)
            && $typeEligible($row, $types)
            && ($noise === 'all' || $coreEligible($row)))->values();
        $total = $filtered->count();
        $page = min($page, max(1, (int) ceil($total / $pageSize)));
        $data = $filtered->slice(($page - 1) * $pageSize, $pageSize)->values()->all();
        $data = $this->listStates->overlay($data, $userId);
        foreach ($data as &$row) {
            $status = data_get($row, 'album.list_state.status');
            $row['states']['wanted'] = $status === 'want_to_listen';
            $row['states']['listened'] = $status === 'listened';
        }

        return compact('data', 'generation', 'total', 'page', 'counts');
    }

    /** @param Collection<int,ArtistDiscographyItem> $items
     * @return Collection<int,array{item:ArtistDiscographyItem,canonical_id:string,entity:mixed}>
     */
    private function canonicalItems(Collection $items): Collection
    {
        return $items->map(function (ArtistDiscographyItem $item): ?array {
            $entity = $item->releaseGroup;
            if ($entity?->status !== 'active' || $entity->kind !== 'release_group') {
                $entity = $this->canonicalEntities->resolve($item->release_group_id, 'release_group');
            }
            if ($entity === null) {
                return null;
            }
            $entity->loadMissing(['metadata', 'artwork']);

            return ['item' => $item, 'canonical_id' => $entity->id, 'entity' => $entity];
        })->filter()->unique('canonical_id')->values();
    }

    /** @param list<string> $ids
     * @return list<string>
     */
    private function identityIds(array $ids): array
    {
        $all = collect($ids)->unique()->values();
        $frontier = $all;
        for ($depth = 0; $depth < 10 && $frontier->isNotEmpty(); $depth++) {
            $frontier = CatalogEntity::query()->whereIn('redirect_entity_id', $frontier)->pluck('id')
                ->reject(fn (string $id): bool => $all->contains($id))->values();
            $all = $all->merge($frontier)->unique()->values();
        }

        return $all->all();
    }

    /** @param list<string> $ids
     * @return array<string,array{plex_item_id:string,count:int,observed:bool,artwork:?array}>
     */
    private function activeHoldings(array $ids): array
    {
        $states = [];
        Holding::query()->whereIn('release_group_id', $ids)
            ->whereHas('plexAlbum', fn ($query) => $query->where('item_type', 'album')->whereNull('removed_at'))
            ->with('plexAlbum.artwork')
            ->orderByDesc('is_primary_playback_copy')
            ->get()->each(function (Holding $holding) use (&$states): void {
                $canonical = $this->canonicalEntities->resolve($holding->release_group_id, 'release_group');
                if ($canonical === null) {
                    return;
                }
                $state = $states[$canonical->id] ?? [
                    'plex_item_id' => $holding->plex_album_item_id,
                    'count' => 0,
                    'observed' => false,
                    'artwork' => $this->artwork->for($holding->plexAlbum),
                ];
                $state['count']++;
                $state['observed'] = $state['observed'] || (int) ($holding->plexAlbum?->view_count ?? 0) > 0;
                $states[$canonical->id] = $state;
            });

        return $states;
    }

    /** @param list<string> $ids
     * @return array<string,true>
     */
    private function latestRecommendations(string $userId, array $ids): array
    {
        $run = RecommendationRun::query()->where('user_id', $userId)->where('intent', 'beyond_library')
            ->where('status', 'completed')->whereHas('items')->latest('generated_at')->first();
        $states = [];
        $run?->items()->whereIn('entity_id', $ids)->pluck('entity_id')->each(function (string $id) use (&$states): void {
            $canonical = $this->canonicalEntities->resolve($id, 'release_group');
            if ($canonical !== null) {
                $states[$canonical->id] = true;
            }
        });

        return $states;
    }

    /** @param list<string> $ids
     * @return array<string,true>
     */
    private function latestUpcoming(array $ids): array
    {
        $generation = UpcomingReleaseGeneration::query()->latest('generated_at')->first();
        $states = [];
        $generation?->items()->whereIn('release_group_id', $ids)->whereDate('release_date', '>=', today())
            ->pluck('release_group_id')->each(function (string $id) use (&$states): void {
                $canonical = $this->canonicalEntities->resolve($id, 'release_group');
                if ($canonical !== null) {
                    $states[$canonical->id] = true;
                }
            });

        return $states;
    }

    /** @param list<string> $ids
     * @return array<string,array{last_listened_at:?string}>
     */
    private function observedListening(array $ids): array
    {
        $states = [];
        PlayAggregate::query()->whereIn('release_group_entity_id', $ids)->where('play_count', '>', 0)->get()
            ->each(function (PlayAggregate $aggregate) use (&$states): void {
                $canonical = $this->canonicalEntities->resolve($aggregate->release_group_entity_id, 'release_group');
                if ($canonical !== null) {
                    $states[$canonical->id] = ['last_listened_at' => $aggregate->last_listened_at?->toAtomString()];
                }
            });

        return $states;
    }
}
