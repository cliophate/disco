<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Music\Discovery\ArtistDiscographyRefreshService;
use Illuminate\Http\JsonResponse;

class ArtistDiscographyRefreshController extends Controller
{
    public function __invoke(string $id, ArtistDiscographyRefreshService $refreshes): JsonResponse
    {
        return response()->json(['data' => $refreshes->request($id, true)], 202);
    }
}
