<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\EvaluacionInduccion;
use App\Models\OnboardingAvance;
use App\Services\CicloLaboral\CicloLaboralService;
use App\Services\Onboarding\EvaluacionInduccionService;
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
        private readonly EvaluacionInduccionService $evaluacionInduccion,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $colaborador = $usuario->colaborador;
        abort_unless($colaborador instanceof Colaborador, 403, 'Tu cuenta no está ligada a un colaborador.');

        $datos = $this->ciclo->misPendientes($colaborador, $usuario);
        $proceso = $this->onboarding->procesoActual($colaborador);
        $datos['induccionContenido'] = $proceso !== null ? $this->evaluacionInduccion->resumenContenido($proceso) : null;
        $datos['todo_listo'] = $datos['todo_listo'] && ($datos['induccionContenido'] === null || $datos['induccionContenido']['completado']);
        $evaluacionInduccion = $proceso !== null ? $this->evaluacionInduccion->habilitarPara($proceso) : null;
        $datos['evaluacionInduccion'] = null;

        if ($evaluacionInduccion !== null) {
            $datos['evaluacionInduccion'] = $this->evaluacionInduccion->aArray($evaluacionInduccion, $usuario);
            $datos['todo_listo'] = $datos['todo_listo'] && ! $datos['evaluacionInduccion']['puede_presentar'];
        }

        return response()->json(['data' => $datos]);
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

    public function marcarContenidoVisto(Request $request, OnboardingAvance $avance): JsonResponse
    {
        $this->onboarding->marcarContenidoVisto($avance, $request->user());
        $proceso = $avance->loadMissing('proceso')->proceso;
        $evaluacion = $this->evaluacionInduccion->habilitarPara($proceso);

        return response()->json(['data' => ['evaluacionInduccion' => $evaluacion !== null ? $this->evaluacionInduccion->aArray($evaluacion, $request->user()) : null]]);
    }

    public function contenidoInduccion(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->evaluacionInduccion->contenidoPublico()]);
    }

    public function capitulosInduccion(Request $request, OnboardingAvance $avance): JsonResponse
    {
        $avance->loadMissing('proceso');

        return response()->json(['data' => [
            'avance_id' => $avance->id,
            'capitulos' => $this->evaluacionInduccion->capitulos($avance, $request->user()),
            'resumen' => $this->evaluacionInduccion->resumenContenido($avance->proceso),
        ]]);
    }

    public function registrarProgresoInduccion(Request $request, OnboardingAvance $avance): JsonResponse
    {
        $datos = $request->validate(['capitulo' => ['required', 'string', 'max:80']]);

        $this->onboarding->registrarProgresoCapitulo($avance, $request->user(), $datos['capitulo']);

        $proceso = $avance->loadMissing('proceso')->proceso;
        $this->evaluacionInduccion->habilitarPara($proceso);

        return response()->json(['data' => $this->evaluacionInduccion->resumenContenido($proceso->fresh())]);
    }

    public function presentarInduccion(Request $request, EvaluacionInduccion $evaluacion): JsonResponse
    {
        $this->authorize('presentar', $evaluacion);
        $datos = $request->validate([
            'respuestas' => ['required', 'array'],
            'respuestas.a' => ['required', 'array'],
            'respuestas.b' => ['required', 'array'],
            'respuestas.c' => ['required', 'array'],
            'respuestas.d' => ['required', 'array'],
            'opinion' => ['nullable', 'array'],
            'opinion.claridad' => ['nullable', 'integer', 'min:1', 'max:5'],
            'opinion.conocimiento_puesto' => ['nullable', 'integer', 'min:1', 'max:5'],
            'opinion.bienvenida' => ['nullable', 'integer', 'min:1', 'max:5'],
            'opinion.tema_reforzar' => ['nullable', 'string', 'max:500'],
        ]);

        $intento = $this->evaluacionInduccion->presentar($evaluacion, $request->user(), $datos['respuestas'], $datos['opinion'] ?? null);

        return response()->json(['data' => ['intento_id' => $intento->id, 'evaluacionInduccion' => $this->evaluacionInduccion->aArray($evaluacion->fresh(), $request->user())]]);
    }
}
