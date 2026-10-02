<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\OnboardingAvance;
use App\Services\CicloLaboral\CicloLaboralService;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Mi proceso" del colaborador en la app (etapa, qué falta, siguiente
 * acción, inducción y expediente) y la presentación de su evaluación de
 * onboarding. Mismos services que Portal\MiProcesoController.
 */
class MiProcesoController extends Controller
{
    public function __construct(
        private readonly CicloLaboralService $ciclo,
        private readonly OnboardingService $onboarding,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $colaborador = $usuario->colaborador;
        abort_unless($colaborador instanceof Colaborador, 403, 'Tu cuenta no está ligada a un colaborador.');

        return response()->json(['data' => $this->ciclo->misPendientes($colaborador, $usuario)]);
    }

    public function evaluacion(Request $request, OnboardingAvance $avance): JsonResponse
    {
        $datos = $request->validate([
            'respuestas' => ['required', 'array'],
            'respuestas.*' => ['required', 'integer', 'min:0'],
        ]);

        $intento = $this->onboarding->presentarEvaluacion($avance, $request->user(), $datos['respuestas']);
        $colaborador = $request->user()->colaborador;

        return response()->json(['data' => [
            'intento' => [
                'numero' => $intento->numero,
                'calificacion' => (float) $intento->calificacion,
                'aprobado' => $intento->aprobado,
            ],
            'proceso' => $colaborador instanceof Colaborador ? $this->ciclo->misPendientes($colaborador, $request->user()) : null,
        ]]);
    }
}
