<?php

namespace App\Music\Discovery;

use App\Http\Presenters\AlbumListStatePresenter;
use App\Http\Presenters\AlbumPresenter;
use App\Models\UpcomingReleaseGeneration;
use App\Models\UpcomingReleaseItem;

class UpcomingReleaseService
{
    public function __construct(
        private readonly ArtistSeedService $artistSeeds,
        private readonly AlbumPresenter $albums,
        private readonly AlbumListStatePresenter $listStates,
    ) {}

    /**
     * @return array{data:list<array<string,mixed>>,generation:?UpcomingReleaseGeneration,total:int,page:int}
     */
    public function page(string $userId, string $view, ?string $generationId, int $page, int $pageSize): array
    {
        $generation = $generationId === null
            ? UpcomingReleaseGeneration::query()->latest('generated_at')->first()
            : UpcomingReleaseGeneration::query()->findOrFail($generationId);
        if ($generation === null) {
            return ['data' => [], 'generation' => null, 'total' => 0, 'page' => 1];
        }

        $items = UpcomingReleaseItem::query()
            ->where('generation_id', $generation->id)
            ->with(['releaseGroup.metadata', 'releaseGroup.artwork'])
            ->orderBy('general_rank')
            ->get();
        $seeds = $this->artistSeeds->exactMbidStates($userId);
        $items = $items->map(function (UpcomingReleaseItem $item) use ($seeds): array {
            $match = ['explicit' => false, 'implicit' => false];
            foreach ($item->artist_mbids as $mbid) {
                $state = $seeds[strtolower($mbid)] ?? null;
                if ($state !== null) {
                    $match['explicit'] = $match['explicit'] || $state['explicit'];
                    $match['implicit'] = $match['implicit'] || $state['implicit'];
                }
            }

            return ['item' => $item, 'match' => $match];
        });
        if ($view === 'for-you') {
            $items = $items
                ->filter(fn (array $candidate): bool => $candidate['match']['explicit'] || $candidate['match']['implicit'])
                ->sortBy(fn (array $candidate): array => [
                    $candidate['match']['explicit'] ? 0 : 1,
                    $candidate['item']->general_rank,
                ])
                ->values();
        }

        $total = $items->count();
        $page = min($page, max(1, (int) ceil($total / $pageSize)));
        $pageItems = $items->slice(($page - 1) * $pageSize, $pageSize)->values()
            ->map(fn (array $candidate): array => $this->present($candidate['item'], $candidate['match']))
            ->all();

        return [
            'data' => $this->listStates->overlay($pageItems, $userId),
            'generation' => $generation,
            'total' => $total,
            'page' => $page,
        ];
    }

    /** @param array{explicit:bool,implicit:bool} $match
     * @return array<string, mixed>
     */
    private function present(UpcomingReleaseItem $item, array $match): array
    {
        $album = $this->albums->external($item->releaseGroup);
        $album['title'] = $item->title;
        $album['artist'] = [
            'id' => count($item->artist_mbids) === 1 ? data_get($album, 'artist.id') : null,
            'name' => $item->artist_credit_name,
            'portrait' => null,
            'type' => null,
            'area' => null,
            'genres' => [],
        ];
        $album['year'] = $item->release_date->year;
        $album['release_type'] = $item->primary_type;
        $album['first_release_date'] = [
            'year' => $item->release_date->year,
            'month' => $item->release_date->month,
            'day' => $item->release_date->day,
            'precision' => 'day',
        ];

        $matchType = match (true) {
            $match['explicit'] && $match['implicit'] => 'followed_and_library',
            $match['explicit'] => 'followed',
            $match['implicit'] => 'library',
            default => null,
        };
        $reason = match ($matchType) {
            'followed_and_library' => 'A followed artist who is also represented in your active library.',
            'followed' => 'An artist you explicitly follow.',
            'library' => 'An artist represented in your active library.',
            default => null,
        };

        return [
            'id' => $item->id,
            'album' => $album,
            'release_date' => $item->release_date->toDateString(),
            'primary_type' => $item->primary_type,
            'secondary_types' => $item->secondary_types,
            'artwork_status' => $item->artwork_status,
            'musicbrainz' => [
                'release_group_mbid' => $item->release_group_mbid,
                'release_mbid' => $item->release_mbid,
                'artist_mbids' => $item->artist_mbids,
            ],
            'personalization' => ['match' => $matchType, 'reason' => $reason],
            'provenance' => $item->provenance,
        ];
    }
}
