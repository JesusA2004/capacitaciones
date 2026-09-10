<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Controller;
use App\Services\RhMobile\RhDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly RhDashboardService $dashboard) {}

    public function __invoke(Request $request): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.mobile.dashboard.ver'), 403);

        return response()->json($this->dashboard->resumen($usuario));
    }
}
