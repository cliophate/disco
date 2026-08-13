<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Music\Personal\AlbumListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AlbumListStateController extends Controller
{
    public function update(Request $request, string $id, AlbumListService $lists): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:want_to_listen,listened'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'source' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $item = $lists->update((string) $request->user()->id, $id, $validated);

        return response()->json(['data' => $lists->present($item)]);
    }

    public function destroy(Request $request, string $id, AlbumListService $lists): Response
    {
        $lists->remove((string) $request->user()->id, $id);

        return response()->noContent();
    }
}
