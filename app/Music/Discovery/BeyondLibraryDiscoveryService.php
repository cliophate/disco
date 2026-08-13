<?php

namespace App\Music\Discovery;

use App\Http\Presenters\AlbumPresenter;
use App\Models\AlbumListItem;
use App\Models\ExternalIdentifier;
use App\Models\Holding;
use App\Models\RecommendationFeedback;
use App\Models\RecommendationItem;
use App\Models\RecommendationRun;
use App\Music\Personal\AlbumListService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BeyondLibraryDiscoveryService
{
    public function __construct(private readonly AlbumPresenter $presenter, private readonly ArtistSeedService $artistSeeds, private readonly AlbumListService $albumLists) {}

    /** @return array{section:array<string,mixed>,recommendations:list<array<string,mixed>>,run_id:string}|null */
    public function forUser(string $userId, ?int $limit = 20, bool $randomize = false): ?array
    {
        $run = $this->latestRun($userId, withEvidence: true);
        if ($run === null) {
            return null;
        }
        $items = $this->eligibleItems($run, $userId);
        $seeds = $this->artistSeeds->forUser($userId);
        $items = $items->sortBy(fn (RecommendationItem $item): string => ($this->seedMatch($item, $seeds) === null ? '1:' : '0:').str_pad((string) $item->rank, 10, '0', STR_PAD_LEFT))->values();
        if ($randomize) {
            $items = $items->partition(fn (RecommendationItem $item): bool => $this->seedMatch($item, $seeds) !== null)
                ->flatMap(fn (Collection $partition): Collection => $partition->shuffle())->values();
        }
        $items = ($limit === null ? $items : $items->take($limit))->values();
        if ($items->isEmpty()) {
            return null;
        }
        $recommendations = $items->map(fn (RecommendationItem $item): array => $this->presentItem($item, $this->seedMatch($item, $seeds)))->all();

        return [
            'run_id' => $run->id,
            'section' => [
                'type' => 'beyond-library',
                'title' => 'Beyond your library',
                'description' => 'Albums not in your collection, resolved from ListenBrainz recording recommendations through MusicBrainz.',
                'items' => collect($recommendations)->map(fn (array $recommendation): array => [
                    'album' => $recommendation['album'],
                    'reasons' => collect($recommendation['reasons'])
                        ->map(fn (array $reason): array => collect($reason)->only(['code', 'text', 'source'])->all())
                        ->all(),
                    'lens' => 'Beyond your library',
                ])->all(),
            ],
            'recommendations' => $recommendations,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function forArtist(string $userId, string $artistId, ?string $exactArtistMbid = null, int $limit = 10): array
    {
        if ($exactArtistMbid === null) {
            $artistMbids = ExternalIdentifier::query()
                ->where('entity_id', $artistId)
                ->where('namespace', 'musicbrainz.artist')
                ->where('status', 'active')
                ->pluck('value');
            if ($artistMbids->count() !== 1) {
                return [];
            }
            $exactArtistMbid = $artistMbids->first();
        }
        if (! Str::isUuid($exactArtistMbid)) {
            return [];
        }
        $exactArtistMbid = strtolower($exactArtistMbid);

        $run = $this->latestRun($userId);
        if ($run === null) {
            return [];
        }

        return $this->eligibleItems($run, $userId)
            ->filter(function (RecommendationItem $item) use ($artistId, $exactArtistMbid): bool {
                $group = $item->entity?->releaseGroup;
                $secondaryTypes = collect($group?->secondary_types ?? [])->map(fn (mixed $type): string => strtolower((string) $type));
                if (strtolower((string) $group?->primary_type) !== 'album' || $secondaryTypes->contains('compilation')) {
                    return false;
                }

                return collect($item->entity?->metadata?->artist_credit ?? [])->contains(
                    fn (mixed $credit): bool => is_array($credit)
                        && (array_key_exists('artist_entity_id', $credit)
                            ? $credit['artist_entity_id'] === $artistId
                            : (is_string($credit['artist_mbid'] ?? null) && strtolower($credit['artist_mbid']) === $exactArtistMbid)),
                );
            })
            ->take($limit)
            ->map(fn (RecommendationItem $item): array => $this->presenter->external($item->entity))
            ->values()
            ->all();
    }

    /** @return array{data:list<array<string,mixed>>,meta:array{run_id:?string,shuffle:string,filter:string,filters:array{all:int,album:int,ep:int,single:int},current_page:int,last_page:int,per_page:int,total:int,eligible_total:int}} */
    public function browseForUser(string $userId, int $page, int $pageSize, string $shuffle, ?string $runId = null, string $filter = 'all'): array
    {
        $run = $runId === null
            ? $this->latestRun($userId)
            : RecommendationRun::query()
                ->where('id', $runId)
                ->where('user_id', $userId)
                ->where('intent', 'beyond_library')
                ->where('status', 'completed')
                ->whereHas('items')
                ->with(['items.entity.metadata', 'items.entity.artwork', 'items.entity.releaseGroup'])
                ->first();
        if ($runId !== null && $run === null) {
            throw new NotFoundHttpException('Recommendation run not found.');
        }

        $seeds = $this->artistSeeds->forUser($userId);
        $eligible = $run === null ? collect() : $this->eligibleItems($run, $userId);
        $filters = [
            'all' => $eligible->count(),
            'album' => $eligible->filter(fn (RecommendationItem $item): bool => $this->releaseType($item) === 'album')->count(),
            'ep' => $eligible->filter(fn (RecommendationItem $item): bool => $this->releaseType($item) === 'ep')->count(),
            'single' => $eligible->filter(fn (RecommendationItem $item): bool => $this->releaseType($item) === 'single')->count(),
        ];
        $items = ($filter === 'all' ? $eligible : $eligible->filter(fn (RecommendationItem $item): bool => $this->releaseType($item) === $filter))
            ->sortBy(fn (RecommendationItem $item): string => ($this->seedMatch($item, $seeds) === null ? '1:' : '0:').hash('sha256', "{$shuffle}:{$item->id}").$item->id)
            ->values();
        $runTotal = $run?->items->count() ?? 0;
        $total = $items->count();
        $lastPage = max(1, (int) ceil($total / $pageSize));
        $page = min($page, $lastPage);
        $data = $items
            ->slice(($page - 1) * $pageSize, $pageSize)
            ->map(fn (RecommendationItem $item): array => [
                'item_id' => $item->id,
                'entity_id' => $item->entity_id,
                'album' => $this->presenter->external($item->entity),
            ])
            ->values()
            ->all();

        return [
            'data' => $data,
            'meta' => [
                'run_id' => $run?->id,
                'shuffle' => $shuffle,
                'filter' => $filter,
                'filters' => $filters,
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $pageSize,
                'total' => $total,
                'eligible_total' => $eligible->count(),
                'run_total' => $runTotal,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public function forEntity(string $userId, string $entityId): ?array
    {
        $item = RecommendationItem::query()
            ->where('entity_id', $entityId)
            ->whereHas('run', fn ($query) => $query
                ->where('user_id', $userId)
                ->where('intent', 'beyond_library')
                ->where('status', 'completed'))
            ->with(['run', 'entity.metadata', 'entity.artwork', 'evidence'])
            ->get()
            ->sortByDesc(fn (RecommendationItem $candidate) => $candidate->run->generated_at)
            ->first();
        if ($item === null) {
            return null;
        }
        $feedback = RecommendationFeedback::query()
            ->where('user_id', $userId)
            ->where('entity_id', $entityId)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();

        return [
            ...$this->presentItem($item),
            'feedback' => $feedback === null ? null : [
                'action' => $feedback->action,
                'reason' => $feedback->reason,
                'expires_at' => $feedback->expires_at?->toAtomString(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function presentItem(RecommendationItem $item, ?array $seed = null): array
    {
        $reasons = $item->evidence->take(3)->map(fn ($evidence): array => [
            'code' => $evidence->evidence_type,
            'text' => $evidence->display_text,
            'source' => $evidence->source_slug,
            'object_entity_id' => $evidence->object_entity_id,
        ]);
        if ($seed !== null) {
            $reasons->prepend(['code' => 'artist-seed', 'text' => $seed['explicit'] ? "By {$seed['name']}, an artist you follow." : "By {$seed['name']}, represented in your Plex library.", 'source' => 'disco', 'object_entity_id' => $seed['id']]);
        }

        return [
            'item_id' => $item->id,
            'entity_id' => $item->entity_id,
            'score' => (float) $item->score,
            'component_scores' => $item->component_scores,
            'eligibility' => $item->eligibility,
            'module_type' => 'beyond-library',
            'reasons' => $reasons->take(3)->values()->all(),
            'explanation_text' => $item->explanation_text,
            'album' => $this->presenter->external($item->entity),
        ];
    }

    /** @param array<string, array{explicit:bool,implicit:bool,seed:bool}> $seeds */
    private function seedMatch(RecommendationItem $item, array $seeds): ?array
    {
        foreach ($item->entity?->metadata?->artist_credit ?? [] as $credit) {
            $id = is_array($credit) ? ($credit['artist_entity_id'] ?? null) : null;
            if (is_string($id) && isset($seeds[$id])) {
                return ['id' => $id, 'name' => $credit['name'] ?? 'this artist', ...$seeds[$id]];
            }
        }

        return null;
    }

    private function latestRun(string $userId, bool $withEvidence = false): ?RecommendationRun
    {
        $relations = ['items.entity.metadata', 'items.entity.artwork', 'items.entity.releaseGroup'];
        if ($withEvidence) {
            $relations[] = 'items.evidence';
        }

        return RecommendationRun::query()
            ->where('user_id', $userId)
            ->where('intent', 'beyond_library')
            ->where('status', 'completed')
            ->whereHas('items')
            ->latest('generated_at')
            ->with($relations)
            ->first();
    }

    /** @return Collection<int, RecommendationItem> */
    private function eligibleItems(RecommendationRun $run, string $userId): Collection
    {
        $this->albumLists->normalize($userId);
        $entityIds = $run->items->pluck('entity_id');
        $owned = Holding::query()->whereIn('release_group_id', $entityIds)->pluck('release_group_id');
        $excluded = RecommendationFeedback::query()
            ->where('user_id', $userId)
            ->whereIn('entity_id', $entityIds)
            ->whereIn('action', ['not_for_me', 'already_know', 'wrong_match'])
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('entity_id');
        $listened = AlbumListItem::query()->where('user_id', $userId)->where('status', 'listened')->pluck('release_group_entity_id');

        return $run->items
            ->filter(fn ($item): bool => $item->entity?->kind === 'release_group' && $item->entity->status === 'active')
            ->reject(fn ($item): bool => $owned->contains($item->entity_id) || $excluded->contains($item->entity_id) || $listened->contains($item->entity_id));
    }

    private function releaseType(RecommendationItem $item): ?string
    {
        $type = data_get($item->eligibility, 'release_type')
            ?? $item->entity?->releaseGroup?->primary_type
            ?? $item->entity?->metadata?->primary_type;

        return is_string($type) ? strtolower($type) : null;
    }
}
