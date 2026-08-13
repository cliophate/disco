<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Operations\ManualOperationCatalog;
use App\Operations\ManualOperationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ManualOperationController extends Controller
{
    public function index(Request $request, ManualOperationCatalog $catalog, ManualOperationService $operations): JsonResponse
    {
        Gate::authorize('owner');
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'data' => collect($operations->recent((string) $request->user()->id, (int) ($validated['limit'] ?? 25)))
                ->map(fn ($operation): array => $operations->present($operation))
                ->values(),
            'meta' => ['available_operations' => $catalog->keys()],
        ]);
    }

    public function store(Request $request, string $operation, ManualOperationCatalog $catalog, ManualOperationService $operations): JsonResponse
    {
        Gate::authorize('owner');
        abort_if($catalog->find($operation) === null, 404);
        $request->validate([
            'operation_key' => ['prohibited'],
            'parameters' => ['prohibited'],
            'options' => ['prohibited'],
        ]);
        $queued = $operations->queue((string) $request->user()->id, $operation);
        $payload = ['data' => $operations->present($queued['operation'])];

        return $queued['created']
            ? response()->json($payload, 202)
            : response()->json([
                'code' => 'operation_in_progress',
                'message' => 'This operation is already queued or running.',
                ...$payload,
            ], 409);
    }
}
