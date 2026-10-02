<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\OnboardingAvance;
use App\Models\OnboardingProceso;
use App\Services\AlcanceOrganizacionalService;
use App\Services\CicloLaboral\CicloLaboralService;
use App\Services\CicloLaboral\OrganizacionJerarquiaService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Reportes\TableroRhService;
use App\Services\Tareas\TareaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ciclo laboral para aprobadores en la app (gerente, regional, RH): tablero,
 * bandeja de pendientes del ciclo, ficha de la persona y acciones de
 * onboarding. MISMOS services que la web (TableroRhService, TareaService,
 * CicloLaboralService::ficha, OnboardingService); la autorización real vive
 * en ellos.
 */
class CicloLaboralRhController extends Controller
{
    public function __construct(
        private readonly CicloLaboralService $ciclo,
        private readonly OnboardingService $onboarding,
        private readonly TableroRhService $tablero,
        private readonly TareaService $tareas,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly OrganizacionJerarquiaService $jerarquia,
    ) {}

    public function tablero(Request $request): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('dashboard.global.ver') || $usuario->can('dashboard.sucursal.ver'), 403);

        $filtros = $request->validate([
            'mes' => ['nullable', 'date_format:Y-m'],
            'sucursal_id' => ['nullable', 'integer'],
        ]);

        return response()->json(['data' => $this->tablero->construir($usuario, $filtros)]);
    }

    /**
     * Bandeja "¿qué me toca?" del ciclo laboral (tareas abiertas del usuario,
     * por etapa/sucursal/urgencia). Cada pendiente trae tipo, relacionado y
     * acción para abrir el recurso exacto.
     */
    public function pendientes(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'etapa' => ['nullable', 'string', 'max:30'],
            'sucursal_id' => ['nullable', 'integer'],
            'urgencia' => ['nullable', 'in:vencidas,urgentes'],
            'tipo' => ['nullable', 'string', 'max:40'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $pagina = $this->tareas->bandeja($request->user(), [...$filtros, 'solo_ciclo' => true]);

        return response()->json([
            'data' => array_map(fn ($t) => $this->tareas->aArray($t), $pagina->items()),
            'meta' => [
                'current_page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'total' => $pagina->total(),
                'last_page' => $pagina->lastPage(),
                'conteos' => $this->tareas->conteos($request->user()),
            ],
        ]);
    }

    public function ciclo(Request $request, Colaborador $colaborador): JsonResponse
    {
        $usuario = $request->user();
        $enCadena = $usuario->colaborador !== null && $this->jerarquia->estaEnCadenaDeMando($usuario->colaborador, $colaborador);
        abort_unless($this->alcance->alcanzaColaborador($usuario, $colaborador) || $enCadena, 403);

        return response()->json(['data' => $this->ciclo->ficha($colaborador, $usuario)]);
    }

    public function entregarActivo(Request $request, OnboardingProceso $proceso): JsonResponse
    {
        $datos = $request->validate([
            'tipo_activo_id' => ['required', 'integer', 'exists:tipos_activo,id'],
            'identificador' => ['nullable', 'string', 'max:120'],
            'descripcion' => ['nullable', 'string', 'max:190'],
            'entregado_en' => ['nullable', 'date', 'before_or_equal:today'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->onboarding->entregarActivo($proceso, $request->user(), $datos);

        return response()->json(['data' => $this->onboarding->aArray($proceso->refresh(), $request->user())]);
    }

    public function completar(Request $request, OnboardingProceso $proceso): JsonResponse
    {
        $proceso = $this->onboarding->completar($proceso, $request->user());

        return response()->json(['data' => $this->onboarding->aArray($proceso, $request->user())]);
    }

    public function retroalimentar(Request $request, OnboardingAvance $avance): JsonResponse
    {
        $datos = $request->validate(['retroalimentacion' => ['required', 'string', 'max:4000']]);
        $avance = $this->onboarding->retroalimentar($avance, $request->user(), (string) $datos['retroalimentacion']);

        return response()->json(['data' => $this->onboarding->aArray($avance->proceso()->firstOrFail(), $request->user())]);
    }
}
