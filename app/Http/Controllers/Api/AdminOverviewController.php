<?php

namespace App\Http\Controllers\Api;

use App\Admin\AdminOverviewService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AdminOverviewController extends Controller
{
    public function __invoke(Request $request, AdminOverviewService $overview): JsonResponse
    {
        Gate::authorize('owner');

        return response()->json([
            'data' => $overview->summarize((string) $request->user()->id),
        ]);
    }
}
