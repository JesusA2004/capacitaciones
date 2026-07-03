<?php

namespace App\Http\Controllers\MiCapacitacion;

use App\Http\Controllers\Controller;
use App\Models\Asistencia;
use App\Models\Leccion;
use App\Services\Capacitacion\ProgresoService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SesionEnVivoController extends Controller
{
    public function __construct(private readonly ProgresoService $progreso) {}

    public function show(Request $request, Leccion $leccion): Response
    {
        $usuario = $request->user();
        $this->progreso->autorizarAccesoLeccion($usuario, $leccion);

        $sesion = $leccion->sesionEnVivo()->firstOrFail();

        $asistencia = Asistencia::query()
            ->where('sesion_en_vivo_id', $sesion->id)
            ->where('user_id', $usuario->id)
            ->first();

        return Inertia::render('MiCapacitacion/SesionEnVivo', [
            'leccion' => $leccion,
            'sesion' => $sesion,
            'miAsistencia' => $asistencia,
        ]);
    }
}
