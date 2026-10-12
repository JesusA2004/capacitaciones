<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\EvaluacionInduccion;
use App\Models\OnboardingAvance;
use App\Services\Administracion\JerarquiaPuestoService;
use App\Services\CicloLaboral\CicloLaboralService;
use App\Services\Onboarding\EvaluacionInduccionService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Organigrama\MiniOrganigramaService;
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
        private readonly JerarquiaPuestoService $jerarquia,
        private readonly MiniOrganigramaService $miniOrganigrama,
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

    public function capitulosInduccion(Request $request, OnboardingAvance $avance): Response
    {
        return Inertia::render('Portal/Induccion', [
            'avanceId' => $avance->id,
            'capitulos' => $this->evaluacionInduccion->capitulos($avance, $request->user()),
            'resumen' => $this->evaluacionInduccion->resumenContenido($avance->loadMissing('proceso')->proceso),
            // CTA "Ver organigrama completo" del capítulo 4: solo si su cuenta
            // ya puede abrir la pantalla de Administración → Organigrama.
            'puedeVerOrganigramaCompleto' => $request->user()->can('organigrama.ver') || $request->user()->can('puestos.administrar'),
        ]);
    }

    public function registrarProgresoInduccion(Request $request, OnboardingAvance $avance): RedirectResponse
    {
        $datos = $request->validate(['capitulo' => ['required', 'string', 'max:80']]);

        $this->onboarding->registrarProgresoCapitulo($avance, $request->user(), $datos['capitulo']);

        $proceso = $avance->loadMissing('proceso')->proceso;
        $this->evaluacionInduccion->habilitarPara($proceso);

        return back();
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

    /**
     * Organigrama dinámico del capítulo 4 (corporativo/comercial/sucursal):
     * puesto + ocupante activo real + hijos, nunca una imagen fija ni
     * personas hardcodeadas (ver MiniOrganigramaService, Fase 3).
     */
    public function organigramaInduccion(Request $request, string $vista): JsonResponse
    {
        $raizId = $this->miniOrganigrama->raizDeVista($vista);
        abort_unless($raizId !== null, 404, 'Vista de organigrama no reconocida.');

        $request->merge(['raiz_puesto_id' => $raizId]);
        $puestos = $this->jerarquia->arbol($request);
        $this->jerarquia->agregarOcupantes($puestos, $request->user(), $request);

        return response()->json(['data' => $this->miniOrganigrama->anidar($puestos, $raizId)]);
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
