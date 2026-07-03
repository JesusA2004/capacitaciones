<?php

namespace App\Http\Controllers;

use App\Services\Calendario\CalendarioService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class CalendarioController extends Controller
{
    public function __construct(private readonly CalendarioService $service) {}

    public function index(Request $request): Response
    {
        $anio = $request->integer('anio') ?: (int) now()->format('Y');
        $mes = $request->integer('mes') ?: (int) now()->format('n');

        $inicioMes = Carbon::create($anio, $mes, 1)->startOfDay();
        $finMes = $inicioMes->clone()->endOfMonth()->endOfDay();

        return Inertia::render('Calendario/Index', [
            'anio' => $anio,
            'mes' => $mes,
            'eventos' => $this->service->eventosDelMes($request->user(), $inicioMes, $finMes),
        ]);
    }
}
