<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\GuardarModuloOnboardingRequest;
use App\Http\Requests\Onboarding\GuardarTipoActivoRequest;
use App\Models\EvaluacionInduccion;
use App\Models\EvaluacionInduccionIntento;
use App\Models\OnboardingAvance;
use App\Models\OnboardingModulo;
use App\Models\OnboardingProceso;
use App\Models\Puesto;
use App\Models\TipoActivo;
use App\Services\Onboarding\AcuseDescriptivoPuestoService;
use App\Services\Onboarding\EvaluacionInduccionService;
use App\Services\Onboarding\OnboardingCatalogoService;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Onboarding (Etapa 3) en la web: operación (entrega de activos, cierre,
 * retroalimentación de RH) y catálogo. La regla vive en OnboardingService,
 * el mismo que usa la API.
 */
class OnboardingController extends Controller
{
    public function __construct(
        private readonly OnboardingService $onboarding,
        private readonly OnboardingCatalogoService $catalogo,
        private readonly EvaluacionInduccionService $evaluacionInduccion,
        private readonly AcuseDescriptivoPuestoService $acusesDescriptivo,
    ) {}

    public function entregarActivo(Request $request, OnboardingProceso $proceso): RedirectResponse
    {
        $datos = $request->validate([
            'tipo_activo_id' => ['required', 'integer', 'exists:tipos_activo,id'],
            'identificador' => ['nullable', 'string', 'max:120'],
            'descripcion' => ['nullable', 'string', 'max:190'],
            'entregado_en' => ['nullable', 'date', 'before_or_equal:today'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->onboarding->entregarActivo($proceso, $request->user(), $datos);

        return back()->with('toast', ['type' => 'success', 'message' => 'Entrega registrada con su carta responsiva.']);
    }

    /**
     * Vista previa de la carta responsiva con lo capturado en el diálogo de
     * entrega, antes de confirmarla (no registra nada).
     */
    public function vistaPreviaResponsiva(Request $request, OnboardingProceso $proceso): HttpResponse
    {
        $datos = $request->validate([
            'tipo_activo_id' => ['required', 'integer', 'exists:tipos_activo,id'],
            'identificador' => ['nullable', 'string', 'max:120'],
            'descripcion' => ['nullable', 'string', 'max:190'],
            'entregado_en' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        return response($this->onboarding->vistaPreviaResponsiva($proceso, $request->user(), $datos), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="responsiva-vista-previa.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function completar(Request $request, OnboardingProceso $proceso): RedirectResponse
    {
        $this->onboarding->completar($proceso, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Onboarding completado: el colaborador inicia operación en campo.']);
    }

    public function retroalimentar(Request $request, OnboardingAvance $avance): RedirectResponse
    {
        $datos = $request->validate(['retroalimentacion' => ['required', 'string', 'max:4000']]);
        $this->onboarding->retroalimentar($avance, $request->user(), (string) $datos['retroalimentacion']);

        return back()->with('toast', ['type' => 'success', 'message' => 'Retroalimentación enviada: la reevaluación quedó habilitada.']);
    }

    public function mostrarInduccion(Request $request, EvaluacionInduccionIntento $intento): Response
    {
        $intento->loadMissing('evaluacion');
        $this->authorize('calificar', $intento->evaluacion);

        return Inertia::render('Rh/Onboarding/CalificarInduccion', [
            'evaluacion' => $this->evaluacionInduccion->aArray($intento->evaluacion, $request->user()),
            'casosPracticos' => $this->evaluacionInduccion->detalleCasosPracticos($intento),
            'revision' => $this->evaluacionInduccion->detalleRevision($intento),
            'intentoId' => $intento->id,
            'yaCalificado' => ! $intento->pendienteCalificacionRh(),
            'descriptivoPuesto' => $this->acusesDescriptivo->resumenRh($intento->evaluacion->colaborador, $request->user()),
        ]);
    }

    public function calificarInduccion(Request $request, EvaluacionInduccionIntento $intento): RedirectResponse
    {
        $this->authorize('calificar', $intento->evaluacion);
        $datos = $request->validate([
            'puntos' => ['required', 'array'],
            'puntos.*' => ['required', 'array'],
            'puntos.*.*' => ['required', 'boolean'],
            'comentario' => ['nullable', 'string', 'max:4000'],
        ]);

        $intento = $this->evaluacionInduccion->calificarCasosPracticos($intento, $request->user(), $datos['puntos'], $datos['comentario'] ?? null);

        return back()->with('toast', $intento->aprobado
            ? ['type' => 'success', 'message' => sprintf('Aprobada con %s/30.', number_format((float) $intento->calificacion_total, 1))]
            : ['type' => 'warning', 'message' => sprintf('Obtuvo %s/30: da retroalimentación y habilita la reevaluación (máximo 5 días hábiles).', number_format((float) $intento->calificacion_total, 1))]);
    }

    public function retroalimentarInduccion(Request $request, EvaluacionInduccion $evaluacion): RedirectResponse
    {
        $this->authorize('calificar', $evaluacion);
        $datos = $request->validate(['retroalimentacion' => ['required', 'string', 'max:4000']]);
        $this->evaluacionInduccion->retroalimentar($evaluacion, $request->user(), (string) $datos['retroalimentacion']);

        return back()->with('toast', ['type' => 'success', 'message' => 'Retroalimentación enviada: la reevaluación quedó habilitada.']);
    }

    public function configuracion(Request $request): Response
    {
        abort_unless($request->user()->can('onboarding.gestionar') && $request->user()->can('onboarding.configurar'), 403);

        return Inertia::render('Rh/Onboarding/Configuracion', [
            'modulos' => OnboardingModulo::query()->with('puesto:id,nombre')->orderBy('tipo')->orderBy('orden')->get()
                ->map(fn (OnboardingModulo $m) => [
                    'id' => $m->id,
                    'titulo' => $m->titulo,
                    'descripcion' => $m->descripcion,
                    'tipo' => $m->tipo->value,
                    'puesto_id' => $m->puesto_id,
                    'puesto' => $m->puesto?->nombre,
                    'orden' => $m->orden,
                    'contenido_url' => $m->contenido_url,
                    // Módulo con evaluación formal (Inducción Mr. Lana): su
                    // contenido son capítulos estructurados (JSON) — se manda
                    // ya decodificado para dibujarlo, nunca como texto crudo.
                    'evaluacion_formal_clave' => $m->evaluacion_formal_clave,
                    'contenido' => $m->evaluacion_formal_clave === null ? $m->contenido : null,
                    'capitulos' => $m->evaluacion_formal_clave !== null ? $m->contenido_estructurado : [],
                    'preguntas' => $m->preguntas ?? [],
                    'calificacion_minima' => (float) $m->calificacion_minima,
                    'obligatorio' => $m->obligatorio,
                    'activo' => $m->activo,
                ])->values(),
            'tiposActivo' => TipoActivo::query()->orderBy('nombre')->get(),
            'puestos' => Puesto::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'evaluacionFormal' => $this->catalogo->resumenEvaluacionFormal($request->user()),
            'rutas' => [
                'induccion_institucional' => route('induccion.presentacion'),
                'vista_colaborador' => route('rh.onboarding.induccion.vista-colaborador'),
            ],
        ]);
    }

    public function guardarModulo(GuardarModuloOnboardingRequest $request): RedirectResponse
    {
        $this->catalogo->guardarModulo($request->validated(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Módulo guardado.']);
    }

    public function actualizarModulo(GuardarModuloOnboardingRequest $request, OnboardingModulo $modulo): RedirectResponse
    {
        $this->catalogo->guardarModulo($request->validated(), $request->user(), $modulo);

        return back()->with('toast', ['type' => 'success', 'message' => 'Módulo actualizado.']);
    }

    public function guardarTipoActivo(GuardarTipoActivoRequest $request): RedirectResponse
    {
        $this->catalogo->guardarTipoActivo($request->validated(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Tipo de activo guardado.']);
    }

    public function actualizarTipoActivo(GuardarTipoActivoRequest $request, TipoActivo $tipoActivo): RedirectResponse
    {
        $this->catalogo->guardarTipoActivo($request->validated(), $request->user(), $tipoActivo);

        return back()->with('toast', ['type' => 'success', 'message' => 'Tipo de activo actualizado.']);
    }
}
