<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\AlbumListStatePresenter;
use App\Music\Discovery\BeyondLibraryDiscoveryService;
use App\Music\Discovery\RecommendationImpressionRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BeyondLibraryController extends Controller
{
    public function __invoke(Request $request, BeyondLibraryDiscoveryService $service, AlbumListStatePresenter $listStates, RecommendationImpressionRecorder $impressions): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'array'],
            'page.number' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'page.size' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'shuffle' => ['required', 'uuid'],
            'run_id' => ['sometimes', 'uuid'],
            'type' => ['sometimes', 'in:all,album,ep,single'],
        ]);
        $page = (int) data_get($validated, 'page.number', 1);
        $pageSize = (int) data_get($validated, 'page.size', 12);
        $result = $service->browseForUser(
            (string) $request->user()->id,
            $page,
            $pageSize,
            $validated['shuffle'],
            $validated['run_id'] ?? null,
            $validated['type'] ?? 'all',
        );
        $result['data'] = $listStates->overlay($result['data'], (string) $request->user()->id);
        $page = $result['meta']['current_page'];
        $runId = $result['meta']['run_id'];
        $filter = $result['meta']['filter'];
        $impressions->recordItems(
            (string) $request->user()->id,
            collect($result['data'])->pluck('item_id')->filter(fn ($id): bool => is_string($id))->all(),
            'beyond',
            "{$runId}:{$validated['shuffle']}:{$filter}:{$page}:{$pageSize}",
            ['run_id' => $runId, 'shuffle' => $validated['shuffle'], 'filter' => $filter, 'page' => $page, 'page_size' => $pageSize],
        );
        $pageUrl = function (int $targetPage) use ($pageSize, $request, $runId, $validated): string {
            $query = $request->query();
            data_set($query, 'page.number', $targetPage);
            data_set($query, 'page.size', $pageSize);
            $query['shuffle'] = $validated['shuffle'];
            if ($runId !== null) {
                $query['run_id'] = $runId;
            }

            return $request->url().'?'.http_build_query($query);
        };
        $lastPage = $result['meta']['last_page'];

        return response()->json([
            ...$result,
            'links' => [
                'first' => $pageUrl(1),
                'prev' => $page > 1 ? $pageUrl($page - 1) : null,
                'next' => $page < $lastPage ? $pageUrl($page + 1) : null,
                'last' => $pageUrl($lastPage),
            ],
        ]);
    }
}
