<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\OwnerPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request, OwnerPresenter $presenter): JsonResponse
    {
        return response()->json(['data' => $presenter->present($request->user())]);
    }
}
