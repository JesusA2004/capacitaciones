<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\RespondePaginado;
use App\Http\Controllers\Controller;
use App\Http\Requests\CicloLaboral\AutorizarEvaluacionRequest;
use App\Http\Requests\CicloLaboral\CapturarEvaluacionRequest;
use App\Http\Requests\CicloLaboral\CrearSolicitudExcepcionRequest;
use App\Http\Requests\CicloLaboral\DecisionRequest;
use App\Http\Requests\CicloLaboral\ResolverSolicitudExcepcionRequest;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\SolicitudExcepcionPeriodoPrueba;
use App\Services\Contratos\EvaluacionPeriodoPruebaService;
use App\Services\Contratos\SolicitudExcepcionPeriodoPruebaService;
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

    public function __construct(
        private readonly EvaluacionPeriodoPruebaService $evaluaciones,
        private readonly SolicitudExcepcionPeriodoPruebaService $excepciones,
    ) {}

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

    /**
     * Solicitar la excepción de Dirección General (FO-RH-EPP-001): nunca un
     * botón "forzar aprobado" — esta es la única puerta.
     */
    public function solicitarExcepcion(CrearSolicitudExcepcionRequest $request, EvaluacionPeriodoPrueba $evaluacion): JsonResponse
    {
        $this->authorize('solicitarExcepcion', $evaluacion);

        $solicitud = $this->excepciones->crear($evaluacion, $request->user(), $request->validated(), $request->file('documento_soporte'));

        return response()->json(['data' => $this->excepciones->aArray($solicitud)], 201);
    }

    /**
     * Resolver la excepción: EXCLUSIVO de Dirección General.
     */
    public function resolverExcepcion(ResolverSolicitudExcepcionRequest $request, SolicitudExcepcionPeriodoPrueba $solicitud): JsonResponse
    {
        $this->authorize('resolver', $solicitud);

        $datos = $request->validated();
        $resuelta = $this->excepciones->resolver($solicitud, $request->user(), (bool) $datos['aprobar'], $datos['comentario'] ?? null);

        return response()->json(['data' => $this->excepciones->aArray($resuelta)]);
    }
}
