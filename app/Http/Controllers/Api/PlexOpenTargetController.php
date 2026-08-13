<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlexItem;
use Illuminate\Http\JsonResponse;

class PlexOpenTargetController extends Controller
{
    public function __invoke(PlexItem $plexItem): JsonResponse
    {
        abort_if($plexItem->removed_at !== null, 404);
        if ($plexItem->item_type === 'artist') {
            abort_unless($plexItem->matches()
                ->where('match_scope', 'agent')
                ->where('status', 'confirmed')
                ->whereHas('entity', fn ($entities) => $entities->where('kind', 'agent')->where('status', 'active'))
                ->exists(), 404);
        }
        $plexItem->load('library.server');
        $machine = $plexItem->library->server->machine_identifier;
        $expectedMachine = (string) config('services.plex.expected_machine_identifier');
        $expectedLibrary = (string) config('services.plex.expected_library_uuid');
        if ($expectedMachine === '' || $expectedLibrary === ''
            || ! hash_equals($expectedMachine, $machine)
            || ! hash_equals($expectedLibrary, (string) $plexItem->library->section_uuid)) {
            return response()->json([
                'code' => 'conflict',
                'status' => 'unavailable',
                'message' => 'The synchronized Plex target no longer matches the configured server and library.',
            ], 409);
        }
        $key = rawurlencode('/library/metadata/'.$plexItem->rating_key);
        $url = 'https://app.plex.tv/desktop/#!/server/'.rawurlencode($machine).'/details?key='.$key;

        return response()->json([
            'status' => 'exact',
            'url' => $url,
        ])->withHeaders(['Referrer-Policy' => 'no-referrer']);
    }
}
