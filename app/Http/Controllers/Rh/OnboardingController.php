<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\GuardarModuloOnboardingRequest;
use App\Http\Requests\Onboarding\GuardarTipoActivoRequest;
use App\Models\OnboardingAvance;
use App\Models\OnboardingModulo;
use App\Models\OnboardingProceso;
use App\Models\Puesto;
use App\Models\TipoActivo;
use App\Services\Onboarding\OnboardingCatalogoService;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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

    public function configuracion(Request $request): Response
    {
        abort_unless($request->user()->can('onboarding.gestionar'), 403);

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
                    'contenido' => $m->contenido,
                    'preguntas' => $m->preguntas ?? [],
                    'calificacion_minima' => (float) $m->calificacion_minima,
                    'obligatorio' => $m->obligatorio,
                    'activo' => $m->activo,
                ])->values(),
            'tiposActivo' => TipoActivo::query()->orderBy('nombre')->get(),
            'puestos' => Puesto::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
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
