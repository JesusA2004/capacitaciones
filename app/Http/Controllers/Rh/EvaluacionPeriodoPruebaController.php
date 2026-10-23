<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\CicloLaboral\AutorizarEvaluacionRequest;
use App\Http\Requests\CicloLaboral\CrearSolicitudExcepcionRequest;
use App\Http\Requests\CicloLaboral\DecisionAprobacionRequest;
use App\Http\Requests\CicloLaboral\ResolverSolicitudExcepcionRequest;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\SolicitudExcepcionPeriodoPrueba;
use App\Services\Contratos\EvaluacionPeriodoPruebaService;
use App\Services\Contratos\SolicitudExcepcionPeriodoPruebaService;
use Illuminate\Http\RedirectResponse;

/**
 * Periodo de prueba (Etapa 4) en la web, lado RH: autorizar o devolver la
 * AUTOEVALUACIÓN que contestó el colaborador (la contesta él desde «Mi
 * espacio», ver Portal\EvaluacionPeriodoPruebaController — RH nunca la
 * contesta). Mismo service y misma Policy que la API.
 */
class EvaluacionPeriodoPruebaController extends Controller
{
    public function __construct(
        private readonly EvaluacionPeriodoPruebaService $evaluaciones,
        private readonly SolicitudExcepcionPeriodoPruebaService $excepciones,
    ) {}

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
        $this->authorize('devolver', $evaluacion);
        $this->evaluaciones->devolver($evaluacion, $request->user(), $request->motivo());

        return back()->with('toast', ['type' => 'success', 'message' => 'Evaluación devuelta al colaborador: se le avisó para que la corrija.']);
    }

    /**
     * Pedir la excepción de Dirección General (FO-RH-EPP-001): nunca un
     * botón "forzar aprobado" — esta es la única puerta.
     */
    public function solicitarExcepcion(CrearSolicitudExcepcionRequest $request, EvaluacionPeriodoPrueba $evaluacion): RedirectResponse
    {
        $this->authorize('solicitarExcepcion', $evaluacion);
        $this->excepciones->crear($evaluacion, $request->user(), $request->validated(), $request->file('documento_soporte'));

        return back()->with('toast', ['type' => 'success', 'message' => 'Excepción enviada a Dirección General.']);
    }

    /**
     * Resolver la excepción: EXCLUSIVO de Dirección General.
     */
    public function resolverExcepcion(ResolverSolicitudExcepcionRequest $request, SolicitudExcepcionPeriodoPrueba $solicitud): RedirectResponse
    {
        $this->authorize('resolver', $solicitud);
        $datos = $request->validated();
        $this->excepciones->resolver($solicitud, $request->user(), (bool) $datos['aprobar'], $datos['comentario'] ?? null);

        return back()->with('toast', ['type' => 'success', 'message' => $datos['aprobar'] ? 'Excepción aprobada.' : 'Excepción rechazada.']);
    }
}
