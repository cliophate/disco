<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Music\Metadata\PipelineDiagnosticService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PipelineDiagnosticsController extends Controller
{
    public function __invoke(string $pipeline, Request $request, PipelineDiagnosticService $diagnostics): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['exact', 'fresh', 'stale', 'ambiguous', 'missing', 'conflict', 'failed', 'queued', 'ready'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'size' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        return response()->json($diagnostics->paginate(
            $pipeline,
            $validated['status'],
            (int) ($validated['page'] ?? 1),
            (int) ($validated['size'] ?? 25),
        ));
    }
}
