<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Music\Discovery\UpcomingReleaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UpcomingReleaseController extends Controller
{
    public function __invoke(Request $request, UpcomingReleaseService $upcoming): JsonResponse
    {
        $validated = $request->validate([
            'view' => ['sometimes', 'in:for-you,all'],
            'generation_id' => ['sometimes', 'uuid'],
            'page' => ['sometimes', 'array'],
            'page.number' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'page.size' => ['sometimes', 'integer', 'min:1', 'max:48'],
        ]);
        $view = $validated['view'] ?? 'for-you';
        $page = (int) data_get($validated, 'page.number', 1);
        $pageSize = (int) data_get($validated, 'page.size', 24);
        $result = $upcoming->page(
            (string) $request->user()->id,
            $view,
            $validated['generation_id'] ?? null,
            $page,
            $pageSize,
        );
        $generation = $result['generation'];
        $page = $result['page'];
        $lastPage = max(1, (int) ceil($result['total'] / $pageSize));
        $pageUrl = function (int $target) use ($generation, $pageSize, $request, $view): string {
            $query = $request->query();
            $query['view'] = $view;
            data_set($query, 'page.number', $target);
            data_set($query, 'page.size', $pageSize);
            if ($generation !== null) {
                $query['generation_id'] = $generation->id;
            }

            return $request->url().'?'.http_build_query($query);
        };
        $stale = $generation?->expires_at?->isPast() ?? false;
        $coverage = $generation?->coverage ?? [];

        return response()->json([
            'data' => $result['data'],
            'meta' => [
                'generation_id' => $generation?->id,
                'generated_at' => $generation?->generated_at?->toAtomString(),
                'expires_at' => $generation?->expires_at?->toAtomString(),
                'stale' => $stale,
                'status' => $generation === null ? 'empty' : ($stale ? 'stale' : 'ready'),
                'view' => $view,
                'horizon_days' => $generation?->horizon_days,
                'horizon_reason' => $generation?->horizon_reason ?? 'No cached upcoming-release generation is available yet.',
                'window_start' => data_get($coverage, 'window_start'),
                'window_end' => data_get($coverage, 'window_end'),
                'past_days' => data_get($coverage, 'past_days'),
                'future_days' => data_get($coverage, 'future_days'),
                'coverage' => $coverage,
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $pageSize,
                'total' => $result['total'],
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
