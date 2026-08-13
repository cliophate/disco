<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Music\Discovery\ArtistDiscographyRefreshService;
use App\Music\Discovery\ArtistDiscographyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArtistDiscographyController extends Controller
{
    public function __invoke(Request $request, string $id, ArtistDiscographyService $discographies, ArtistDiscographyRefreshService $refreshes): JsonResponse
    {
        $validated = $request->validate([
            'view' => ['sometimes', 'in:missing,present,all'],
            'types' => ['sometimes', 'in:albums,albums_eps,all'],
            'noise' => ['sometimes', 'in:core,all'],
            'generation_id' => ['sometimes', 'uuid'],
            'page' => ['sometimes', 'array'],
            'page.number' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'page.size' => ['sometimes', 'integer', 'min:1', 'max:48'],
        ]);
        $view = $validated['view'] ?? 'missing';
        $types = $validated['types'] ?? 'albums';
        $noise = $validated['noise'] ?? 'core';
        $pageSize = (int) data_get($validated, 'page.size', 24);
        $result = $discographies->page(
            (string) $request->user()->id,
            $id,
            $view,
            $types,
            $noise,
            $validated['generation_id'] ?? null,
            (int) data_get($validated, 'page.number', 1),
            $pageSize,
        );
        $generation = $result['generation'];
        $page = $result['page'];
        $lastPage = max(1, (int) ceil($result['total'] / $pageSize));
        $pageUrl = function (int $target) use ($generation, $noise, $pageSize, $request, $types, $view): string {
            $query = ['view' => $view, 'types' => $types, 'noise' => $noise];
            data_set($query, 'page.number', $target);
            data_set($query, 'page.size', $pageSize);
            if ($generation !== null) {
                $query['generation_id'] = $generation->id;
            }

            return $request->getPathInfo().'?'.http_build_query($query);
        };
        $stale = $generation?->expires_at?->isPast() ?? false;
        $refresh = $refreshes->request($id);

        return response()->json([
            'data' => $result['data'],
            'meta' => [
                'generation_id' => $generation?->id,
                'generated_at' => $generation?->generated_at?->toAtomString(),
                'expires_at' => $generation?->expires_at?->toAtomString(),
                'status' => $generation === null ? 'empty' : ($stale ? 'stale' : 'ready'),
                'refresh' => $refresh,
                'stale' => $stale,
                'truncated' => $generation?->truncated ?? false,
                'source_total' => $generation?->source_total ?? 0,
                'view' => $view,
                'types' => $types,
                'noise' => $noise,
                'counts' => $result['counts'],
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
