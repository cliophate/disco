<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Music\Metadata\PipelineDiagnosticService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;

class RetryPipelineDiagnosticController extends Controller
{
    public function __invoke(string $pipeline, string $id, PipelineDiagnosticService $diagnostics): JsonResponse
    {
        try {
            $result = $diagnostics->retry($pipeline, $id);

            return $result['attempted']
                ? response()->json(['data' => $result])
                : response()->json(['code' => 'retry_not_eligible', 'message' => $result['repair_note'], 'next_retry_at' => $result['next_retry_at'] ?? null], 409);
        } catch (LockTimeoutException) {
            return response()->json(['code' => 'retry_in_progress', 'message' => 'A retry for this entity is already in progress.'], 409);
        }
    }
}
