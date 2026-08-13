<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\AlbumListStatePresenter;
use App\Models\HomeEdition;
use App\Music\Discovery\HomeDiscoveryService;
use App\Music\Discovery\HomeProjectionVersion;
use App\Music\Discovery\RecommendationImpressionRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class HomeLensController extends Controller
{
    public function __invoke(Request $request, string $type, AlbumListStatePresenter $listStates, HomeDiscoveryService $discovery, HomeProjectionVersion $projectionVersion, RecommendationImpressionRecorder $impressions): JsonResponse
    {
        abort_unless(in_array($type, HomeDiscoveryService::configuration()['module_order'], true) && $type !== 'beyond-library', 404);
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'size' => ['sometimes', 'integer', 'min:1', 'max:48'],
            'version' => ['sometimes', 'string', 'size:64'],
        ]);
        $userId = (string) $request->user()->id;
        $calendarDay = now()->toDateString();
        $currentVersion = $projectionVersion->current($userId, $calendarDay);
        $version = (string) ($validated['version'] ?? $currentVersion);
        $cacheKey = "disco:home-lens:{$userId}:{$version}:{$type}";
        $lens = Cache::get($cacheKey);
        if (! is_array($lens)) {
            abort_if($version !== $currentVersion, 409, 'This lens edition has expired. Return to the first page to refresh it.');
            $lens = $discovery->lens($type, $userId, $calendarDay);
            abort_if($lens === null, 404);
            Cache::put($cacheKey, $lens, now()->addHour());
        }

        $page = (int) ($validated['page'] ?? 1);
        $pageSize = (int) ($validated['size'] ?? 24);
        $items = $lens['items'];
        $total = count($items);
        $lastPage = max(1, (int) ceil($total / $pageSize));
        $page = min($page, $lastPage);
        $pageUrl = function (int $targetPage) use ($pageSize, $request, $version): string {
            $query = $request->query();
            $query['page'] = $targetPage;
            $query['size'] = $pageSize;
            $query['version'] = $version;

            return $request->url().'?'.http_build_query($query);
        };
        $pageItems = array_values(array_slice($items, ($page - 1) * $pageSize, $pageSize));
        $pageItems = $listStates->overlay($pageItems, $userId);
        $edition = HomeEdition::query()->where('user_id', $userId)->where('version_hash', $version)->first();
        if ($edition !== null) {
            $impressions->recordEntities(
                $userId,
                $edition->recommendation_run_id,
                collect($pageItems)->pluck('album.id')->filter(fn ($id): bool => is_string($id))->all(),
                'home-lens',
                "{$version}:{$type}:{$page}:{$pageSize}",
                ['edition_id' => $edition->id, 'lens' => $type, 'page' => $page, 'page_size' => $pageSize],
            );
        }

        return response()->json([
            'data' => $pageItems,
            'section' => collect($lens)->except('items')->all(),
            'meta' => [
                'version' => $version,
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
}
