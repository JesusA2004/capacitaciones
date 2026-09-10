<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Mobile\MobileBootstrapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileBootstrapController extends Controller
{
    public function __construct(private readonly MobileBootstrapService $bootstrap) {}

    public function __invoke(Request $request): JsonResponse
    {
        return response()->json($this->bootstrap->bootstrap($request->user()));
    }
}
