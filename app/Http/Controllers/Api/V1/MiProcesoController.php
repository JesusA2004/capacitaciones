<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\EvaluacionInduccion;
use App\Models\OnboardingAvance;
use App\Models\User;
use App\Services\Administracion\JerarquiaPuestoService;
use App\Services\CicloLaboral\CicloLaboralService;
use App\Services\Onboarding\AccesoInduccionColaboradorService;
use App\Services\Onboarding\EvaluacionInduccionService;
use App\Services\Onboarding\InduccionPdfService;
use App\Services\Onboarding\MaterialInduccionService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Onboarding\ReproduccionVideoInduccionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
        private readonly JerarquiaPuestoService $jerarquia,
        private readonly InduccionPdfService $induccionPdf,
        private readonly AccesoInduccionColaboradorService $accesoInduccion,
        private readonly MaterialInduccionService $materialInduccion,
        private readonly ReproduccionVideoInduccionService $videoInduccion,
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

        // La inducción la presenta RH: cuándo puede verla / contestarla.
        $datos['accesoInduccion'] = $this->accesoInduccion->resumen($colaborador);

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

    /**
     * Inducción en modo presentación: RH (p. ej. por Meet) o el colaborador
     * SOLO durante la sesión de RH que lo incluye (403 con el motivo antes y
     * después) — los 7 capítulos reales sin avance ni progreso, mismo
     * EvaluacionInduccionService::presentacion() que la web.
     */
    public function presentacionInduccion(Request $request): JsonResponse
    {
        // "pdf": el documento OFICIAL que la app muestra en su visor. Los
        // capítulos quedan solo para versiones anteriores de la app.
        $presentacion = $this->evaluacionInduccion->presentacion($request->user()) ?? ['titulo' => (string) config('induccion.titulo'), 'capitulos' => []];

        return response()->json(['data' => [...$presentacion, 'pdf' => $this->induccionPdf->datosVisor(true)]]);
    }

    /**
     * PDF oficial de la inducción, solo con sesión (nunca una URL pública).
     */
    public function pdfInduccion(Request $request): BinaryFileResponse
    {
        return $this->induccionPdf->respuesta($request->user());
    }

    /**
     * El visor reporta cada página que MOSTRÓ al colaborador titular.
     */
    public function registrarPaginaInduccion(Request $request, OnboardingAvance $avance): JsonResponse
    {
        $datos = $request->validate(['pagina' => ['required', 'integer', 'min:1']]);
        $this->induccionPdf->registrarPagina($avance, $request->user(), (int) $datos['pagina']);

        return response()->json(['data' => $this->evaluacionInduccion->resumenContenido($avance->loadMissing('proceso')->proceso)]);
    }

    /**
     * «Terminé la inducción»: habilita la evaluación formal.
     */
    public function terminarInduccion(Request $request, OnboardingAvance $avance): JsonResponse
    {
        $this->induccionPdf->terminar($avance, $request->user());
        $proceso = $avance->loadMissing('proceso')->proceso;
        $evaluacion = $this->evaluacionInduccion->habilitarPara($proceso);

        return response()->json(['data' => [
            'resumen' => $this->evaluacionInduccion->resumenContenido($proceso),
            'evaluacionInduccion' => $evaluacion !== null ? $this->evaluacionInduccion->aArray($evaluacion, $request->user()) : null,
        ]]);
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
            'pdf' => $this->induccionPdf->datosVisor(true),
            'puede_ver_organigrama_completo' => $request->user()->can('organigrama.ver') || $request->user()->can('puestos.administrar'),
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

    /**
     * Organigrama dinámico del capítulo 4 (corporativo/comercial/sucursal):
     * puesto + ocupante activo real + hijos, nunca una imagen fija ni
     * personas hardcodeadas (ver MiniOrganigramaService, Fase 3).
     */
    public function organigramaInduccion(string $vista): JsonResponse
    {
        // Institucional: ocupantes reales sin acotar por alcance, solo datos
        // organizacionales mínimos (mismo método que la web).
        $organigrama = $this->jerarquia->organigramaInstitucional($vista);
        abort_unless($organigrama !== null, 404, 'Vista de organigrama no reconocida.');

        return response()->json(['data' => $organigrama]);
    }

    /**
     * Material de explicación (audio/video) activo de la inducción asignada:
     * tipo + URL firmada (audio) o si el video ya está listo (el reproductor
     * usa los endpoints de video). Mismo MaterialInduccionService que la web.
     */
    public function materialInduccion(Request $request, OnboardingAvance $avance): JsonResponse
    {
        $this->autorizarInduccion($request->user(), $avance);

        return response()->json(['data' => $this->materialInduccion->datosParaColaborador($avance)]);
    }

    /**
     * Arranca el VIDEO obligatorio de la inducción (mismo shape que
     * MiCapacitacion\ReproduccionController::iniciar). El anti-adelanto y el
     * «completado» los decide el servidor (ReproduccionVideoInduccionService).
     */
    public function videoIniciarInduccion(Request $request, OnboardingAvance $avance): JsonResponse
    {
        $usuario = $request->user();
        $this->autorizarInduccion($usuario, $avance);
        $recurso = $this->videoInduccion->recurso($avance);
        abort_unless($recurso !== null && $recurso->estaDisponible(), 422, 'El video de la inducción no está disponible todavía.');

        return response()->json(['data' => $this->videoInduccion->iniciarSesion($usuario, $avance)]);
    }

    public function videoHeartbeatInduccion(Request $request, OnboardingAvance $avance): JsonResponse
    {
        $usuario = $request->user();
        $this->autorizarInduccion($usuario, $avance);
        $datos = $request->validate(['posicion_segundos' => ['required', 'integer', 'min:0']]);

        return response()->json(['data' => $this->videoInduccion->registrarHeartbeat($usuario, $avance, (int) $datos['posicion_segundos'])]);
    }

    public function audioProgresoInduccion(Request $request, OnboardingAvance $avance): JsonResponse
    {
        $usuario = $request->user();
        $this->autorizarInduccion($usuario, $avance);
        $datos = $request->validate(['posicion_segundos' => ['required', 'integer', 'min:0']]);

        $progreso = (array) ($avance->progreso_contenido ?? []);
        $audio = (array) ($progreso['audio'] ?? []);
        $avance->update(['progreso_contenido' => [
            ...$progreso,
            'audio' => [
                ...$audio,
                'ultima_posicion' => (int) $datos['posicion_segundos'],
                'max_posicion' => max((int) ($audio['max_posicion'] ?? 0), (int) $datos['posicion_segundos']),
                'ultima_vez' => now()->toIso8601String(),
            ],
        ]]);

        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * @throws AuthorizationException
     */
    private function autorizarInduccion(User $usuario, OnboardingAvance $avance): void
    {
        $avance->loadMissing('proceso', 'modulo');

        if ($usuario->colaborador_id === null || $usuario->colaborador_id !== $avance->proceso->colaborador_id) {
            throw new AuthorizationException('Solo el colaborador titular ve el material de su inducción.');
        }

        abort_if($avance->modulo->evaluacion_formal_clave === null, 404);
        $this->accesoInduccion->exigirContenidoVisible($usuario);
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
