<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EtapaCicloLaboral;
use App\Http\Controllers\Controller;
use App\Models\Candidato;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\Reingreso;
use App\Models\SolicitudInterna;
use App\Models\Sucursal;
use App\Models\TareaRh;
use App\Models\TareaRhDescarte;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Tareas\DescarteTareasService;
use App\Services\Tareas\RecepcionPendientesService;
use App\Services\Tareas\ReconciliacionPendientesService;
use App\Services\Tareas\ResumenPendientesService;
use App\Services\Tareas\TareaService;
use App\Support\Paginacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "¿Qué me toca hacer?": bandeja de pendientes del usuario (TareaService,
 * la misma fuente que GET /api/v1/tareas). Ver docs/PENDIENTES.md.
 *
 * La lista, el número grande y el contador del sidebar son la MISMA
 * consulta (pendientes abiertos que le llegan, sin los que quitó de su
 * lista). Al abrirla se limpian los pendientes que ya se cumplieron.
 */
class PendienteController extends Controller
{
    /**
     * Quién entra a «Mis pendientes» (web): exactamente los permisos con los
     * que el sidebar muestra el acceso (resources/js/composables/useMainNavItems.ts,
     * grupo Inicio). La API móvil usa su propio permiso `rh.pendientes.ver`.
     */
    public const PERMISOS_ACCESO = [
        'ciclo.preautorizar',
        'ciclo.autorizar_rh',
        'evaluaciones.capturar',
        'evaluaciones.autorizar',
        'onboarding.entregar_activos',
        'cierres.solicitar',
        'cierres.gestionar',
        'expedientes.revisar',
        'solicitudes.revisar',
        'solicitudes.aprobar',
        'nomina.recibos.crear',
        'candidatos.intervencion_decidir',
        'candidatos.aprobar_entrevista',
    ];

    private const ESTADOS = ['abiertas', 'descartadas', 'resueltas'];

    public function __construct(
        private readonly TareaService $tareas,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly DescarteTareasService $descartes,
    ) {}

    public function index(Request $request, ResumenPendientesService $resumen, ReconciliacionPendientesService $reconciliacion): Response
    {
        $usuario = $request->user();
        abort_unless($usuario->canAny(self::PERMISOS_ACCESO), 403);

        // Lo ya cumplido se quita antes de contar (como máximo cada 2 min).
        $reconciliacion->reconciliarSiToca();

        $estado = in_array($request->query('estado'), self::ESTADOS, true) ? (string) $request->query('estado') : 'abiertas';
        $filtrosVista = [...$request->only(['etapa', 'sucursal_id', 'urgencia', 'tipo']), 'estado' => $estado];

        $tareas = $this->tareas->bandeja($usuario, [
            ...$filtrosVista,
            'per_page' => Paginacion::porPagina(25),
        ])->withQueryString();

        $motivos = $estado === 'descartadas'
            ? TareaRhDescarte::query()->where('user_id', $usuario->id)->whereIn('tarea_rh_id', $tareas->getCollection()->pluck('id'))->pluck('motivo', 'tarea_rh_id')
            : collect();

        $tareas->getCollection()->transform(fn (TareaRh $t) => [
            ...$this->tareas->aArray($t),
            'url' => self::urlDe($t, $usuario),
            'foto_url' => $this->tareas->fotoUrl($t, $usuario),
            'descartada' => $estado === 'descartadas',
            'motivo_descarte' => $motivos->get($t->id),
            'puede_marcar_hecho' => $t->resuelta_en === null && $usuario->can('marcarHecha', $t),
        ]);

        return Inertia::render('Rh/Pendientes/Index', [
            // Centro único: solicitudes, cambios de foto, evaluaciones,
            // candidatos, documentos, bajas, finiquitos, datos faltantes,
            // intervenciones y lotes de nómina (cada uno con su permiso).
            'atajos' => $resumen->atajos($usuario),
            'tareas' => $tareas,
            'conteos' => $this->tareas->conteos($usuario),
            'distribucion' => $this->tareas->distribucion($usuario),
            'por_tipo' => $this->tareas->porTipo($usuario),
            'filtros' => $filtrosVista,
            'opciones' => [
                'etapas' => array_map(
                    fn (EtapaCicloLaboral $e) => ['value' => $e->value, 'etiqueta' => $e->etiqueta()],
                    [EtapaCicloLaboral::Reclutamiento, EtapaCicloLaboral::Contratacion, EtapaCicloLaboral::Onboarding, EtapaCicloLaboral::PeriodoPrueba, EtapaCicloLaboral::Cierre, EtapaCicloLaboral::Reingreso],
                ),
                'sucursales' => Sucursal::query()->whereIn('id', $this->alcance->sucursalesVisiblesIds($usuario))->orderBy('nombre')->get(['id', 'nombre']),
                'motivo_max' => (int) config('pendientes.motivo_max', 255),
            ],
        ]);
    }

    /**
     * «Quitar de mi lista»: solo para quien lo descarta (motivo opcional).
     */
    public function descartar(Request $request, TareaRh $tarea): RedirectResponse
    {
        $this->authorize('descartar', $tarea);
        $datos = $request->validate(
            ['motivo' => ['nullable', 'string', 'max:'.(int) config('pendientes.motivo_max', 255)]],
            [],
            ['motivo' => 'motivo'],
        );

        $this->descartes->descartar($tarea, $request->user(), $datos['motivo'] ?? null);

        return back()->with('toast', ['type' => 'success', 'message' => 'Pendiente quitado de tu lista. Lo encuentras en «Descartados».']);
    }

    public function restaurar(Request $request, TareaRh $tarea): RedirectResponse
    {
        $this->authorize('descartar', $tarea);
        $this->descartes->restaurar($tarea, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'El pendiente regresó a tu lista.']);
    }

    /**
     * «Marcar como hecho»: solo informativos o asignados a la propia cuenta
     * (TareaRhPolicy::marcarHecha); cierra el pendiente para todos.
     */
    public function marcarHecho(Request $request, TareaRh $tarea): RedirectResponse
    {
        $this->authorize('marcarHecha', $tarea);
        $this->descartes->marcarHecha($tarea, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Pendiente marcado como hecho.']);
    }

    /**
     * Catálogo de «Roles y permisos → Pendientes» (qué pendientes le llegan
     * a cada rol). Solo quien administra roles.
     */
    public function catalogoRoles(Request $request, RecepcionPendientesService $recepcion): JsonResponse
    {
        abort_unless($request->user()->can('roles.administrar'), 403);

        return response()->json(['data' => $recepcion->catalogo()]);
    }

    /**
     * «Resolver / Ir»: cada pendiente apunta a la pantalla donde se atiende.
     */
    public static function urlDe(TareaRh $tarea, ?User $usuario = null): ?string
    {
        $tipoRelacionado = $tarea->relacionado_type;

        if ($tipoRelacionado === (new SolicitudInterna)->getMorphClass() && $tarea->relacionado_id !== null) {
            // La corrección que debe responder el propio colaborador se abre
            // en «Mis solicitudes»; lo demás, en la bandeja de RH/gerencia.
            $propia = $usuario !== null && $tarea->asignado_user_id === $usuario->id && $tarea->colaborador_id !== null && $tarea->colaborador_id === $usuario->colaborador_id;

            return route($propia ? 'solicitudes.show' : 'rh.solicitudes.show', $tarea->relacionado_id);
        }

        if (in_array($tipoRelacionado, [(new EvaluacionPeriodoPrueba)->getMorphClass(), (new ContratoLaboral)->getMorphClass()], true)
            && in_array($tarea->tipo->value, ['evaluacion_pendiente', 'evaluacion_por_autorizar', 'contrato_por_vencer'], true)) {
            return route('rh.evaluaciones.index', ['tab' => 'en_proceso']);
        }

        if ($tipoRelacionado === (new Reingreso)->getMorphClass()) {
            return route('rh.reingresos.index');
        }

        if (($tarea->candidato_id !== null && $tarea->colaborador_id === null) || ($tipoRelacionado === (new Candidato)->getMorphClass() && $tarea->candidato_id !== null)) {
            return route('rh.candidatos.show', $tarea->candidato_id);
        }

        if ($tarea->colaborador_id !== null) {
            return route('rh.colaboradores.ciclo', $tarea->colaborador_id);
        }

        return null;
    }
}
