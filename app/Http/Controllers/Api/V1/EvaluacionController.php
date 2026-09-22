<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\RespondePaginado;
use App\Http\Controllers\Controller;
use App\Http\Requests\CicloLaboral\AutorizarEvaluacionRequest;
use App\Http\Requests\CicloLaboral\CapturarEvaluacionRequest;
use App\Http\Requests\CicloLaboral\DecisionRequest;
use App\Models\EvaluacionPeriodoPrueba;
use App\Services\Contratos\EvaluacionPeriodoPruebaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Evaluaciones de periodo de prueba: el jefe ve y captura las de su equipo;
 * RH/Dirección las autoriza (renovar → contrato indeterminado; no renovar →
 * cierre laboral). Autorización por EvaluacionPeriodoPruebaPolicy.
 */
class EvaluacionController extends Controller
{
    use RespondePaginado;

    public function __construct(private readonly EvaluacionPeriodoPruebaService $evaluaciones) {}

    public function index(Request $request): JsonResponse
    {
        return $this->paginado(
            $this->evaluaciones->listar($request->user(), $request->only(['estado', 'per_page'])),
            fn (EvaluacionPeriodoPrueba $e) => $this->evaluaciones->aArray($e),
        );
    }

    public function show(EvaluacionPeriodoPrueba $evaluacion): JsonResponse
    {
        $this->authorize('ver', $evaluacion);

        return response()->json(['data' => $this->evaluaciones->aArray($evaluacion->load(['colaborador', 'contrato']))]);
    }

    public function capturar(CapturarEvaluacionRequest $request, EvaluacionPeriodoPrueba $evaluacion): JsonResponse
    {
        $this->authorize('capturar', $evaluacion);

        return response()->json(['data' => $this->evaluaciones->aArray($this->evaluaciones->capturar($evaluacion, $request->user(), $request->validated()))]);
    }

    public function autorizar(AutorizarEvaluacionRequest $request, EvaluacionPeriodoPrueba $evaluacion): JsonResponse
    {
        $this->authorize('autorizar', $evaluacion);

        return response()->json(['data' => $this->evaluaciones->aArray($this->evaluaciones->autorizar($evaluacion, $request->user(), $request->validated()))]);
    }

    public function devolver(DecisionRequest $request, EvaluacionPeriodoPrueba $evaluacion): JsonResponse
    {
        $this->authorize('autorizar', $evaluacion);
        $request->validate(['motivo' => ['required']]);

        return response()->json(['data' => $this->evaluaciones->aArray($this->evaluaciones->devolver($evaluacion, $request->user(), (string) $request->validated('motivo')))]);
    }
}
