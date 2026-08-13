<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\AlbumListStatePresenter;
use App\Models\HomeEdition;
use App\Music\Discovery\HomeEditionComposer;
use App\Music\Discovery\HomeProjectionVersion;
use App\Music\Discovery\RecommendationImpressionRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiscoverController extends Controller
{
    public function __invoke(Request $request, AlbumListStatePresenter $listStates, HomeEditionComposer $composer, HomeProjectionVersion $projectionVersion, RecommendationImpressionRecorder $impressions): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'array'],
            'page.number' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'page.size' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'edition_id' => ['sometimes', 'uuid'],
        ]);
        $userId = (string) $request->user()->id;
        $edition = isset($validated['edition_id'])
            ? HomeEdition::query()
                ->where('id', $validated['edition_id'])
                ->where('user_id', $userId)
                ->firstOrFail()
            : $this->currentEdition($userId, $composer, $projectionVersion);
        $items = $this->project($edition->payload);
        $page = (int) data_get($validated, 'page.number', 1);
        $pageSize = (int) data_get($validated, 'page.size', 9);
        $total = count($items);
        $lastPage = max(1, (int) ceil($total / $pageSize));
        $pageUrl = function (int $targetPage) use ($edition, $pageSize, $request): string {
            $query = $request->query();
            data_set($query, 'page.number', $targetPage);
            data_set($query, 'page.size', $pageSize);
            $query['edition_id'] = $edition->id;

            return $request->url().'?'.http_build_query($query);
        };
        $pageItems = array_values(array_slice($items, ($page - 1) * $pageSize, $pageSize));
        $pageItems = $listStates->overlay($pageItems, $userId);
        $impressions->recordEntities(
            $userId,
            $edition->recommendation_run_id,
            collect($pageItems)->pluck('recommendation.album.id')->filter(fn ($id): bool => is_string($id))->all(),
            'discover',
            "{$edition->id}:{$page}:{$pageSize}",
            ['edition_id' => $edition->id, 'page' => $page, 'page_size' => $pageSize],
        );

        return response()->json([
            'data' => $pageItems,
            'meta' => [
                'edition_id' => $edition->id,
                'edition_version' => $edition->version_hash,
                'generated_at' => $edition->generated_at?->toAtomString(),
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $pageSize,
                'total' => $total,
            ],
            'links' => [
                'first' => $pageUrl(1),
                'prev' => $page > 1 ? $pageUrl($page - 1) : null,
                'next' => $page < $lastPage ? $pageUrl($page + 1) : null,
                'last' => $pageUrl($lastPage),
            ],
        ]);
    }

    private function currentEdition(string $userId, HomeEditionComposer $composer, HomeProjectionVersion $projectionVersion): HomeEdition
    {
        $calendarDay = now()->toDateString();
        $version = $projectionVersion->current($userId, $calendarDay);

        return HomeEdition::query()
            ->where('user_id', $userId)
            ->where('version_hash', $version)
            ->first() ?? $composer->generate($userId, $calendarDay);
    }

    /** @return list<array<string, mixed>> */
    private function project(array $payload): array
    {
        $albumItems = [];
        $seenAlbums = [];
        $feature = data_get($payload, 'feature');
        $featureId = data_get($feature, 'album.id');
        if (is_array($feature) && is_string($featureId)) {
            $seenAlbums[$featureId] = true;
            $albumItems[] = [
                'id' => "album:{$featureId}",
                'type' => 'album',
                'presentation' => 'feature',
                'span' => 'feature',
                'lens' => $feature['lens'] ?? 'Featured discovery',
                'description' => 'One factual path into or beyond the collection.',
                'recommendation' => $feature,
            ];
        }

        $sections = array_values(array_filter(data_get($payload, 'sections', []), 'is_array'));
        $position = 0;
        do {
            $hasCandidate = false;
            foreach ($sections as $section) {
                $recommendation = data_get($section, "items.{$position}");
                $albumId = data_get($recommendation, 'album.id');
                if (! is_array($recommendation)) {
                    continue;
                }
                $hasCandidate = true;
                if (! is_string($albumId) || isset($seenAlbums[$albumId])) {
                    continue;
                }
                $seenAlbums[$albumId] = true;
                $ordinal = count($albumItems);
                $presentation = $position === 0
                    ? match ($ordinal % 3) {
                        1 => 'editorial',
                        2 => 'overlay',
                        default => 'text',
                    }
                : match (true) {
                    $ordinal % 7 === 0 => 'text',
                    $ordinal % 5 === 0 => 'overlay',
                    default => 'cover',
                };
                $albumItems[] = [
                    'id' => "album:{$albumId}",
                    'type' => 'album',
                    'presentation' => $presentation,
                    'span' => $presentation === 'editorial' ? 'wide' : 'standard',
                    'lens' => $recommendation['lens'] ?? data_get($section, 'title', 'Discovery lens'),
                    'description' => data_get($section, 'description'),
                    'recommendation' => $recommendation,
                ];
            }
            $position++;
        } while ($hasCandidate);

        $artists = collect(data_get($payload, 'recent_artists', []))
            ->filter(fn (mixed $artist): bool => is_array($artist) && is_string($artist['id'] ?? null))
            ->take(6)
            ->values();
        $feed = [];
        foreach ($albumItems as $index => $item) {
            $feed[] = $item;
            if (($index + 1) % 3 === 0 && $artists->isNotEmpty()) {
                $artist = $artists->shift();
                $feed[] = [
                    'id' => "artist:{$artist['id']}",
                    'type' => 'artist',
                    'presentation' => 'portrait',
                    'span' => 'standard',
                    'lens' => 'Recently in view',
                    'artist' => $artist,
                ];
            }
        }
        foreach ($artists as $artist) {
            $feed[] = [
                'id' => "artist:{$artist['id']}",
                'type' => 'artist',
                'presentation' => 'portrait',
                'span' => 'standard',
                'lens' => 'Recently in view',
                'artist' => $artist,
            ];
        }

        $editorial = collect(data_get($payload, 'editorial', []))
            ->filter(fn (mixed $item): bool => is_array($item) && is_string($item['id'] ?? null) && is_string($item['url'] ?? null))
            ->values();
        if ($editorial->isNotEmpty()) {
            $mixed = [];
            foreach ($feed as $index => $item) {
                $mixed[] = $item;
                if (($index + 1) % 3 === 0 && $editorial->isNotEmpty()) {
                    $story = $editorial->shift();
                    $mixed[] = [
                        'id' => "editorial:{$story['id']}",
                        'type' => 'editorial',
                        'presentation' => 'story',
                        'span' => 'wide',
                        'editorial' => $story,
                    ];
                }
            }
            foreach ($editorial as $story) {
                $mixed[] = [
                    'id' => "editorial:{$story['id']}",
                    'type' => 'editorial',
                    'presentation' => 'story',
                    'span' => 'wide',
                    'editorial' => $story,
                ];
            }
            $feed = $mixed;
        }

        return $feed;
    }
}
