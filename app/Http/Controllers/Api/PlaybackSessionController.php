<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Music\Plex\PlexPlaybackSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class PlaybackSessionController extends Controller
{
    public function store(Request $request, PlexPlaybackSessionService $sessions): JsonResponse
    {
        $validated = $request->validate(['media_part_id' => ['required', 'uuid']]);

        return response()->json(['data' => $sessions->create($request->user(), $validated['media_part_id'])], 201);
    }

    public function update(Request $request, string $session, PlexPlaybackSessionService $sessions): JsonResponse
    {
        $validated = $request->validate([
            'state' => ['required', Rule::in(['playing', 'paused', 'stopped', 'ended'])],
            'position_ms' => ['required', 'integer', 'min:0'],
        ]);

        return response()->json(['data' => $sessions->update($request->user(), $session, $validated['state'], $validated['position_ms'])]);
    }

    public function destroy(Request $request, string $session, PlexPlaybackSessionService $sessions): Response
    {
        $sessions->destroy($request->user(), $session);

        return response()->noContent();
    }
}
