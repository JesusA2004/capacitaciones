<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EtapaCicloLaboral;
use App\Http\Controllers\Controller;
use App\Models\Sucursal;
use App\Models\TareaRh;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Tareas\ResumenPendientesService;
use App\Services\Tareas\TareaService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "¿Qué me toca hacer?": bandeja de pendientes del ciclo laboral del usuario
 * (TareaService, la misma fuente que GET /api/v1/rh/pendientes).
 */
class PendienteController extends Controller
{
    public function __construct(
        private readonly TareaService $tareas,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    public function index(Request $request, ResumenPendientesService $resumen): Response
    {
        $usuario = $request->user();
        $filtros = [
            ...$request->only(['etapa', 'sucursal_id', 'urgencia', 'tipo']),
            'solo_ciclo' => true,
            'per_page' => 25,
        ];

        $tareas = $this->tareas->bandeja($usuario, $filtros)->withQueryString();
        $tareas->getCollection()->transform(fn (TareaRh $t) => [
            ...$this->tareas->aArray($t),
            'url' => self::urlDe($t),
        ]);

        return Inertia::render('Rh/Pendientes/Index', [
            // Centro único: solicitudes, cambios de foto, evaluaciones,
            // candidatos, documentos, bajas, finiquitos, datos faltantes,
            // intervenciones y lotes de nómina (cada uno con su permiso).
            'atajos' => $resumen->atajos($usuario),
            'tareas' => $tareas,
            'conteos' => $this->tareas->conteos($usuario),
            'distribucion' => $this->tareas->distribucion($usuario),
            'filtros' => $request->only(['etapa', 'sucursal_id', 'urgencia', 'tipo']),
            'opciones' => [
                'etapas' => array_map(
                    fn (EtapaCicloLaboral $e) => ['value' => $e->value, 'etiqueta' => $e->etiqueta()],
                    [EtapaCicloLaboral::Reclutamiento, EtapaCicloLaboral::Contratacion, EtapaCicloLaboral::Onboarding, EtapaCicloLaboral::PeriodoPrueba, EtapaCicloLaboral::Cierre, EtapaCicloLaboral::Reingreso],
                ),
                'sucursales' => Sucursal::query()->whereIn('id', $this->alcance->sucursalesVisiblesIds($usuario))->orderBy('nombre')->get(['id', 'nombre']),
            ],
        ]);
    }

    /**
     * Cada pendiente apunta al objeto donde se ejecuta su acción.
     */
    public static function urlDe(TareaRh $tarea): ?string
    {
        if ($tarea->candidato_id !== null && $tarea->colaborador_id === null) {
            return route('rh.candidatos.show', $tarea->candidato_id);
        }

        if ($tarea->colaborador_id !== null) {
            return route('rh.colaboradores.ciclo', $tarea->colaborador_id);
        }

        return null;
    }
}
