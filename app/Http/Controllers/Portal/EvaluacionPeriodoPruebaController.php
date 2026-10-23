<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\CicloLaboral\CapturarEvaluacionRequest;
use App\Models\Colaborador;
use App\Models\EvaluacionPeriodoPrueba;
use App\Services\Contratos\EvaluacionPeriodoPruebaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Mi evaluación de periodo de prueba» en Mi espacio (web): la
 * AUTOEVALUACIÓN FO-RH-EPP-001 que RH le asignó. Siempre la propia (usuario
 * autenticado); solo la persona evaluada la contesta (Policy «contestar» +
 * EvaluacionPeriodoPruebaService::contestar). Misma regla que la app
 * (Api\V1\EvaluacionPeriodoPruebaColaboradorController).
 */
class EvaluacionPeriodoPruebaController extends Controller
{
    public function __construct(private readonly EvaluacionPeriodoPruebaService $evaluaciones) {}

    public function show(Request $request): Response
    {
        $usuario = $request->user();
        $colaborador = $usuario->colaborador;
        abort_unless($colaborador instanceof Colaborador, 403);

        $evaluacion = $this->evaluaciones->deColaborador($colaborador);

        return Inertia::render('Portal/EvaluacionPeriodoPrueba', [
            'evaluacion' => $evaluacion !== null ? $this->evaluaciones->aArrayColaborador($evaluacion, $usuario) : null,
        ]);
    }

    public function contestar(CapturarEvaluacionRequest $request, EvaluacionPeriodoPrueba $evaluacion): RedirectResponse
    {
        $this->authorize('contestar', $evaluacion);
        $this->evaluaciones->contestar($evaluacion, $request->user(), $request->validated());

        return to_route('portal.evaluacion-periodo-prueba')->with('toast', ['type' => 'success', 'message' => '¡Gracias! Tu evaluación se envió a Recursos Humanos.']);
    }
}
