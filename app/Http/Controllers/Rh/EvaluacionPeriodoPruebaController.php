<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\CicloLaboral\AutorizarEvaluacionRequest;
use App\Http\Requests\CicloLaboral\CapturarEvaluacionRequest;
use App\Http\Requests\CicloLaboral\DecisionAprobacionRequest;
use App\Models\EvaluacionPeriodoPrueba;
use App\Services\Contratos\EvaluacionPeriodoPruebaService;
use Illuminate\Http\RedirectResponse;

/**
 * Periodo de prueba (Etapa 4) en la web: el jefe evalúa (preautorización),
 * RH autoriza o devuelve. Mismo service y misma Policy que la API.
 */
class EvaluacionPeriodoPruebaController extends Controller
{
    public function __construct(private readonly EvaluacionPeriodoPruebaService $evaluaciones) {}

    public function capturar(CapturarEvaluacionRequest $request, EvaluacionPeriodoPrueba $evaluacion): RedirectResponse
    {
        $this->authorize('capturar', $evaluacion);
        $this->evaluaciones->capturar($evaluacion, $request->user(), $request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Evaluación enviada: queda pendiente la autorización final de RH.']);
    }

    public function autorizar(AutorizarEvaluacionRequest $request, EvaluacionPeriodoPrueba $evaluacion): RedirectResponse
    {
        $this->authorize('autorizar', $evaluacion);
        $evaluacion = $this->evaluaciones->autorizar($evaluacion, $request->user(), $request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => $evaluacion->decision_renovar
            ? 'Renovación autorizada: se generó el contrato por tiempo indeterminado.'
            : 'No renovación autorizada: se abrió el cierre laboral (sin baja antes de la fecha efectiva).']);
    }

    public function devolver(DecisionAprobacionRequest $request, EvaluacionPeriodoPrueba $evaluacion): RedirectResponse
    {
        $this->authorize('autorizar', $evaluacion);
        $this->evaluaciones->devolver($evaluacion, $request->user(), $request->motivo());

        return back()->with('toast', ['type' => 'success', 'message' => 'Evaluación devuelta al jefe para corrección.']);
    }
}
