<?php

namespace App\Http\Controllers\MiCapacitacion;

use App\Http\Controllers\Controller;
use App\Http\Requests\MiCapacitacion\StoreEntregaActividadRequest;
use App\Models\EntregaActividad;
use App\Models\Leccion;
use App\Services\Capacitacion\ProgresoService;
use App\Services\Evaluacion\EntregaActividadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EntregaActividadController extends Controller
{
    public function __construct(
        private readonly EntregaActividadService $service,
        private readonly ProgresoService $progreso,
    ) {}

    public function show(Request $request, Leccion $leccion): Response
    {
        $usuario = $request->user();
        $this->progreso->autorizarAccesoLeccion($usuario, $leccion);

        $actividad = $leccion->actividad()->firstOrFail();

        $entregas = EntregaActividad::query()
            ->where('actividad_id', $actividad->id)
            ->where('user_id', $usuario->id)
            ->orderByDesc('version')
            ->get();

        return Inertia::render('MiCapacitacion/Actividad', [
            'leccion' => $leccion,
            'actividad' => $actividad,
            'entregas' => $entregas,
            'puedeEntregar' => $this->service->puedeEntregar($usuario, $actividad),
        ]);
    }

    public function store(StoreEntregaActividadRequest $request, Leccion $leccion): RedirectResponse
    {
        $usuario = $request->user();
        $this->progreso->autorizarAccesoLeccion($usuario, $leccion);

        $actividad = $leccion->actividad()->firstOrFail();

        try {
            $this->service->entregar($usuario, $actividad, $request->validated());
        } catch (\RuntimeException $excepcion) {
            return back()->with('toast', ['type' => 'error', 'message' => $excepcion->getMessage()]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'Entrega registrada correctamente.']);
    }
}
