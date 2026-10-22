<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\EvaluacionInduccion;
use App\Models\OnboardingAvance;
use App\Services\CicloLaboral\CicloLaboralService;
use App\Services\Onboarding\AccesoInduccionColaboradorService;
use App\Services\Onboarding\AcuseDescriptivoPuestoService;
use App\Services\Onboarding\EvaluacionInduccionService;
use App\Services\Onboarding\InduccionPdfService;
use App\Services\Onboarding\MaterialInduccionService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Onboarding\ProgresoInduccionService;
use App\Services\Onboarding\ReproduccionVideoInduccionService;
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
        private readonly AccesoInduccionColaboradorService $accesoInduccion,
        private readonly MaterialInduccionService $materialInduccion,
        private readonly ReproduccionVideoInduccionService $videoInduccion,
        private readonly ProgresoInduccionService $progresoInduccion,
        private readonly AcuseDescriptivoPuestoService $descriptivos,
    ) {}

    public function show(Request $request): Response
    {
        $usuario = $request->user();
        $colaborador = $usuario->colaborador;
        abort_unless($colaborador instanceof Colaborador, 403);

        $datos = $this->ciclo->misPendientes($colaborador, $usuario);
        // El proceso que lleva su inducción (ingreso o reinducción asignada).
        $proceso = $this->accesoInduccion->procesoInduccion($colaborador) ?? $this->onboarding->procesoActual($colaborador);
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

        // Cuándo puede verla / contestarla y su avance por pasos («Mi inducción»).
        $datos['accesoInduccion'] = $this->accesoInduccion->resumen($colaborador);
        $datos['miInduccion'] = $this->progresoInduccion->resumen($colaborador);
        // Descriptivo de puesto que RH le envió (sin contenido: se lee en su pantalla).
        $acuse = $this->descriptivos->vigenteDe($usuario);
        $datos['descriptivoPuesto'] = $acuse !== null ? $this->descriptivos->aArray($acuse) : null;
        $datos['todo_listo'] = $datos['todo_listo'] && ($acuse === null || $acuse->acusado_en !== null);
        // Asignada pero sin contenido visto todavía: tampoco está «al día».
        $datos['todo_listo'] = $datos['todo_listo'] && ! in_array($datos['accesoInduccion']['estado'], ['disponible', 'evaluacion_pendiente'], true);

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
     * «Inducción Mr. Lana» en Mi espacio. El colaborador la ve por su cuenta
     * mientras la tiene asignada y por ver (hojas del PDF oficial + material
     * de explicación, según lo que RH le asignó); antes y después recibe el
     * motivo (sin asignar, evaluación por contestar, en revisión, completada)
     * sin el contenido. Nunca hay controles de presentador aquí, aunque la
     * cuenta también tenga permisos de RH.
     */
    public function verInduccion(Request $request): Response
    {
        $colaborador = $request->user()->colaborador;
        abort_unless($colaborador instanceof Colaborador, 403);

        $acceso = $this->accesoInduccion->resumen($colaborador);
        $avance = $acceso['puede_ver_contenido'] ? $this->accesoInduccion->avanceInduccion($colaborador) : null;

        return Inertia::render('Portal/Induccion', [
            'pdf' => $acceso['puede_ver_contenido'] ? $this->induccionPdf->datosVisor() : null,
            'acceso' => $acceso,
            // Nuevo flujo: la inducción se ve sola (hojas + material) antes de
            // la evaluación. El avance titular es el que lleva el progreso.
            'avanceId' => $avance?->id,
            'material' => $avance !== null ? $this->materialInduccion->datosParaColaborador($avance) : null,
            'videoCompletado' => $avance !== null && $this->videoInduccion->videoCompletado($avance),
            // Pasos (Presentación → Video → Evaluación → Resultado) y qué le
            // asignó RH (presentación y/o material).
            'miInduccion' => $this->progresoInduccion->resumen($colaborador),
            // URLs ya resueltas de los endpoints (evita depender de helpers de
            // Wayfinder recién generados para las rutas nuevas).
            'rutas' => $avance !== null ? [
                'pagina' => route('portal.induccion.pagina', $avance),
                'terminar' => route('portal.induccion.terminar', $avance),
                'video_iniciar' => route('portal.induccion.video.iniciar', $avance),
                'video_heartbeat' => route('portal.induccion.video.heartbeat', $avance),
                'audio_progreso' => route('portal.induccion.audio-progreso', $avance),
            ] : null,
        ]);
    }

    /**
     * Enlaces anteriores al visor por avance (solo el titular) → la
     * «Inducción Mr. Lana» de Mi espacio, que decide si hay contenido
     * visible.
     */
    public function capitulosInduccion(Request $request, OnboardingAvance $avance): RedirectResponse
    {
        $avance->loadMissing('proceso');
        abort_unless($request->user()->colaborador_id !== null && $request->user()->colaborador_id === $avance->proceso->colaborador_id, 403);

        return to_route('portal.induccion.ver');
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

        $proceso = $this->accesoInduccion->procesoInduccion($colaborador) ?? $this->onboarding->procesoActual($colaborador);
        $evaluacion = $proceso !== null ? $this->evaluacionInduccion->habilitarPara($proceso) : null;
        abort_unless($evaluacion !== null && $evaluacion->estado->permitePresentar(), 404, 'Todavía no tienes una evaluación de inducción disponible.');

        return Inertia::render('Portal/EvaluacionInduccion', [
            'contenido' => $this->evaluacionInduccion->contenidoPublico($evaluacion),
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
