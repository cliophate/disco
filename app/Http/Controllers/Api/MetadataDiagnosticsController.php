<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Music\Metadata\MetadataDiagnosticService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MetadataDiagnosticsController extends Controller
{
    public function __invoke(Request $request, MetadataDiagnosticService $diagnostics): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['artist', 'album', 'track'])],
            'category' => ['required', Rule::in(['identity', 'enrichment', 'artwork', 'narrative'])],
            'status' => ['required', Rule::in(['ready', 'missing', 'failed', 'stale', 'pending', 'ambiguous'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'size' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $report = $diagnostics->paginate(
            $validated['type'],
            $validated['category'],
            $validated['status'],
            (int) ($validated['page'] ?? 1),
            (int) ($validated['size'] ?? 25),
        );

        return response()->json($report);
    }
}
