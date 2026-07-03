<?php

namespace App\Http\Controllers;

use App\Services\Reportes\MetricasDashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private readonly MetricasDashboardService $metricas) {}

    public function index(Request $request): Response
    {
        $usuario = $request->user();

        if ($usuario->can('dashboard.global.ver')) {
            return Inertia::render('Dashboard/Global', $this->metricas->global($usuario));
        }

        if ($usuario->can('dashboard.sucursal.ver')) {
            return Inertia::render('Dashboard/Sucursal', $this->metricas->paraSucursal($usuario));
        }

        return Inertia::render('Dashboard/Colaborador', $this->metricas->paraColaborador($usuario));
    }
}
