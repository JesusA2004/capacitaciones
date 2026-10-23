<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CicloLaboral\CapturarEvaluacionRequest;
use App\Models\Colaborador;
use App\Models\EvaluacionPeriodoPrueba;
use App\Services\Contratos\EvaluacionPeriodoPruebaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * App móvil · «Mi evaluación de periodo de prueba» (autoevaluación
 * FO-RH-EPP-001): el colaborador consulta y contesta SU evaluación. Mismo
 * service, misma Policy («contestar») y mismo FormRequest que la web
 * (Portal\EvaluacionPeriodoPruebaController).
 */
class EvaluacionPeriodoPruebaColaboradorController extends Controller
{
    public function __construct(private readonly EvaluacionPeriodoPruebaService $evaluaciones) {}

    public function show(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $colaborador = $usuario->colaborador;
        abort_unless($colaborador instanceof Colaborador, 403);

        $evaluacion = $this->evaluaciones->deColaborador($colaborador);

        return response()->json(['data' => $evaluacion !== null ? $this->evaluaciones->aArrayColaborador($evaluacion, $usuario) : null]);
    }

    public function contestar(CapturarEvaluacionRequest $request, EvaluacionPeriodoPrueba $evaluacion): JsonResponse
    {
        $this->authorize('contestar', $evaluacion);
        $evaluacion = $this->evaluaciones->contestar($evaluacion, $request->user(), $request->validated());

        return response()->json([
            'data' => $this->evaluaciones->aArrayColaborador($evaluacion, $request->user()),
            'message' => '¡Gracias! Tu evaluación se envió a Recursos Humanos.',
        ]);
    }
}
