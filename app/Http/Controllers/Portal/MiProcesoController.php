<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\EvaluacionInduccion;
use App\Models\OnboardingAvance;
use App\Services\CicloLaboral\CicloLaboralService;
use App\Services\Onboarding\EvaluacionInduccionService;
use App\Services\Onboarding\InduccionPdfService;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Mi proceso" (modo colaborador): dónde estoy en mi ciclo laboral, qué me
 * falta y mi inducción. Siempre lee la persona del usuario autenticado —
 * nunca la de otro —; los datos y la calificación salen de los mismos
 * services que usa la app (CicloLaboralService::miProceso, OnboardingService).
 */
class MiProcesoController extends Controller
{
    public function __construct(
        private readonly CicloLaboralService $ciclo,
        private readonly OnboardingService $onboarding,
        private readonly EvaluacionInduccionService $evaluacionInduccion,
        private readonly InduccionPdfService $induccionPdf,
    ) {}

    public function show(Request $request): Response
    {
        $usuario = $request->user();
        $colaborador = $usuario->colaborador;
        abort_unless($colaborador instanceof Colaborador, 403);

        $datos = $this->ciclo->misPendientes($colaborador, $usuario);
        $proceso = $this->onboarding->procesoActual($colaborador);
        $datos['induccionContenido'] = $proceso !== null ? $this->evaluacionInduccion->resumenContenido($proceso) : null;
        // Contenido institucional por ver nunca deja decir "estás al día".
        $datos['todo_listo'] = $datos['todo_listo'] && ($datos['induccionContenido'] === null || $datos['induccionContenido']['completado']);
        $evaluacionInduccion = $proceso !== null ? $this->evaluacionInduccion->habilitarPara($proceso) : null;
        $datos['evaluacionInduccion'] = null;

        if ($evaluacionInduccion !== null) {
            $datos['evaluacionInduccion'] = $this->evaluacionInduccion->aArray($evaluacionInduccion, $usuario);
            // Pendiente de ESTE colaborador (presentarla o reaplicarla) — que
            // "estás al día" nunca oculte una evaluación formal por presentar.
            $datos['todo_listo'] = $datos['todo_listo'] && ! $datos['evaluacionInduccion']['puede_presentar'];
        }

        return Inertia::render('Portal/MiProceso', $datos);
    }

    public function evaluacion(Request $request, OnboardingAvance $avance): RedirectResponse
    {
        $datos = $request->validate([
            'respuestas' => ['required', 'array'],
            'respuestas.*' => ['required', 'integer', 'min:0'],
        ]);

        $intento = $this->onboarding->presentarEvaluacion($avance, $request->user(), $datos['respuestas']);

        return back()->with('toast', $intento->aprobado
            ? ['type' => 'success', 'message' => sprintf('¡Aprobaste con %s!', number_format((float) $intento->calificacion, 1))]
            : ['type' => 'warning', 'message' => sprintf('Obtuviste %s: RH te dará retroalimentación para reevaluar.', number_format((float) $intento->calificacion, 1))]);
    }

    public function marcarContenidoVisto(Request $request, OnboardingAvance $avance): RedirectResponse
    {
        $this->onboarding->marcarContenidoVisto($avance, $request->user());

        $proceso = $avance->loadMissing('proceso')->proceso;
        $this->evaluacionInduccion->habilitarPara($proceso);

        return back();
    }

    /**
     * Inducción del colaborador: el PDF OFICIAL en el visor, con su avance
     * por página (solo el titular; capitulos() valida la propiedad).
     */
    public function capitulosInduccion(Request $request, OnboardingAvance $avance): Response
    {
        $this->evaluacionInduccion->capitulos($avance, $request->user());

        return Inertia::render('Portal/Induccion', [
            'avanceId' => $avance->id,
            'pdf' => $this->induccionPdf->datosVisor(),
            'resumen' => $this->evaluacionInduccion->resumenContenido($avance->loadMissing('proceso')->proceso),
        ]);
    }

    /**
     * El visor reporta cada página que MOSTRÓ (JSON: no recarga la página).
     */
    public function registrarPaginaInduccion(Request $request, OnboardingAvance $avance): JsonResponse
    {
        $datos = $request->validate(['pagina' => ['required', 'integer', 'min:1']]);
        $this->induccionPdf->registrarPagina($avance, $request->user(), (int) $datos['pagina']);

        return response()->json(['data' => $this->evaluacionInduccion->resumenContenido($avance->loadMissing('proceso')->proceso)]);
    }

    /**
     * «Terminé la inducción» → evaluación de inducción (si ya está habilitada).
     */
    public function terminarInduccion(Request $request, OnboardingAvance $avance): RedirectResponse
    {
        $this->induccionPdf->terminar($avance, $request->user());
        $evaluacion = $this->evaluacionInduccion->habilitarPara($avance->loadMissing('proceso')->proceso);

        if ($evaluacion !== null && $evaluacion->estado->permitePresentar()) {
            return to_route('portal.induccion.contenido')->with('toast', ['type' => 'success', 'message' => '¡Terminaste la inducción! Ahora presenta tu evaluación.']);
        }

        return to_route('portal.mi-proceso')->with('toast', ['type' => 'success', 'message' => '¡Terminaste la inducción!']);
    }

    public function contenidoInduccion(Request $request): Response
    {
        $colaborador = $request->user()->colaborador;
        abort_unless($colaborador instanceof Colaborador, 403);

        $proceso = $this->onboarding->procesoActual($colaborador);
        $evaluacion = $proceso !== null ? $this->evaluacionInduccion->habilitarPara($proceso) : null;
        abort_unless($evaluacion !== null && $evaluacion->estado->permitePresentar(), 404, 'Todavía no tienes una evaluación de inducción disponible.');

        return Inertia::render('Portal/EvaluacionInduccion', [
            'contenido' => $this->evaluacionInduccion->contenidoPublico(),
            'evaluacionId' => $evaluacion->id,
        ]);
    }

    public function presentarInduccion(Request $request, EvaluacionInduccion $evaluacion): RedirectResponse
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

        $this->evaluacionInduccion->presentar($evaluacion, $request->user(), $datos['respuestas'], $datos['opinion'] ?? null);

        return redirect()->route('portal.mi-proceso')->with('toast', ['type' => 'success', 'message' => 'Evaluación enviada: Recursos Humanos calificará tus casos prácticos.']);
    }
}
