<?php

namespace App\Http\Controllers;

use App\Services\Administracion\JerarquiaPuestoService;
use App\Services\Onboarding\EvaluacionInduccionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inducción Mr. Lana en MODO PRESENTACIÓN: el mismo contenido real de los 7
 * capítulos (y los mini-organigramas vigentes) para que RH la presente en
 * una sesión por Meet o el colaborador la repase — visible para ambos, sin
 * registrar progreso ni tocar la evaluación (eso sigue siendo
 * Portal\MiProcesoController con el avance propio del colaborador).
 */
class InduccionPresentacionController extends Controller
{
    public function __construct(
        private readonly EvaluacionInduccionService $evaluacionInduccion,
        private readonly JerarquiaPuestoService $jerarquia,
    ) {}

    public function show(Request $request): Response
    {
        $presentacion = $this->evaluacionInduccion->presentacion();
        abort_if($presentacion === null, 404, 'La inducción institucional todavía no tiene contenido cargado.');

        return Inertia::render('Portal/Induccion', [
            'modoPresentacion' => true,
            'avanceId' => null,
            'capitulos' => $presentacion['capitulos'],
            'resumen' => null,
            'puedeVerOrganigramaCompleto' => $request->user()->can('organigrama.ver') || $request->user()->can('puestos.administrar'),
        ]);
    }

    /**
     * Mismo organigrama dinámico del capítulo 4 que ve el colaborador
     * (JerarquiaPuestoService::organigramaInstitucional), para el modo presentación.
     */
    public function organigrama(string $vista): JsonResponse
    {
        $organigrama = $this->jerarquia->organigramaInstitucional($vista);
        abort_unless($organigrama !== null, 404, 'Vista de organigrama no reconocida.');

        return response()->json(['data' => $organigrama]);
    }
}
