<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Enums\EstadoSolicitudVacaciones;
use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\SolicitudVacaciones;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\RhMobile\WorkflowService;
use App\Services\Vacaciones\VacacionesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bandeja de vacaciones para RH/aprobadores desde la app movil. Reutiliza
 * App\Services\Vacaciones\VacacionesService (misma logica que el Portal RH
 * web). Ver seccion 9 del encargo movil.
 */
class VacacionController extends Controller
{
    public function __construct(
        private readonly VacacionesService $vacaciones,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly WorkflowService $workflow,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.vacaciones.ver'), 403);

        $filtros = $request->only(['estado', 'sucursal_id', 'empresa_id']);
        if ($q = $request->string('q')->toString()) {
            $filtros['busqueda'] = $q;
        }

        $vacaciones = $this->vacaciones->paraRevision($usuario, $filtros);

        return response()->json([
            'data' => $vacaciones->getCollection()->map(fn (SolicitudVacaciones $v) => $this->resumen($usuario, $v))->values(),
            'meta' => [
                'current_page' => $vacaciones->currentPage(),
                'per_page' => $vacaciones->perPage(),
                'total' => $vacaciones->total(),
            ],
        ]);
    }

    public function show(Request $request, SolicitudVacaciones $vacacion): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.vacaciones.detalle'), 403);
        abort_unless($this->puedeVer($usuario, $vacacion), 403);

        $vacacion->load(['colaborador:id,name,apellidos,numero_empleado,sucursal_principal_id,puesto_id,fecha_ingreso', 'colaborador.sucursalPrincipal:id,nombre', 'colaborador.puesto:id,nombre', 'usuario', 'revisadoPor:id,name,apellidos']);

        $flujo = $this->workflow->paraVacacion($usuario, $vacacion);
        $persona = $vacacion->personaSolicitante();

        return response()->json([
            'data' => [
                'id' => $vacacion->id,
                'estado' => $vacacion->estado->value,
                'colaborador' => $this->colaboradorResumen($persona),
                'fecha_inicio' => $vacacion->fecha_inicio->toDateString(),
                'fecha_fin' => $vacacion->fecha_fin->toDateString(),
                'dias_solicitados' => $vacacion->dias_solicitados,
                'saldo_disponible' => $persona !== null ? $this->vacaciones->saldoColaborador($persona)['dias_disponibles'] : 0,
                'comentario' => $vacacion->comentario,
                'motivo_rechazo' => $vacacion->motivo_rechazo,
                'acciones_permitidas' => $flujo['acciones_permitidas'],
                'workflow' => $flujo['workflow'],
                'historial' => [],
            ],
        ]);
    }

    public function aprobar(Request $request, SolicitudVacaciones $vacacion): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.vacaciones.aprobar'), 403);
        abort_unless($this->puedeVer($usuario, $vacacion), 404);
        $this->validarPendiente($vacacion);

        $vacacion = $this->vacaciones->aprobar($vacacion, $usuario);

        return response()->json(['message' => 'Solicitud de vacaciones aprobada correctamente', 'data' => ['id' => $vacacion->id, 'estado' => $vacacion->estado->value]]);
    }

    public function rechazar(Request $request, SolicitudVacaciones $vacacion): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.vacaciones.rechazar'), 403);
        abort_unless($this->puedeVer($usuario, $vacacion), 404);
        $this->validarPendiente($vacacion);

        $datos = $request->validate(['motivo' => ['required', 'string', 'max:1000']]);

        $vacacion = $this->vacaciones->rechazar($vacacion, $usuario, $datos['motivo']);

        return response()->json(['message' => 'Solicitud de vacaciones rechazada correctamente', 'data' => ['id' => $vacacion->id, 'estado' => $vacacion->estado->value]]);
    }

    private function validarPendiente(SolicitudVacaciones $vacacion): void
    {
        abort_unless($vacacion->estado === EstadoSolicitudVacaciones::Pendiente, 422, 'Esta solicitud ya no admite esta acción.');
    }

    private function puedeVer(User $usuario, SolicitudVacaciones $vacacion): bool
    {
        $vacacion->loadMissing(['colaborador', 'usuario.colaborador']);

        if ($this->alcance->tieneAlcanceGlobal($usuario)) {
            return true;
        }

        $persona = $vacacion->personaSolicitante();

        return $persona !== null && $this->alcance->puedeVerExpediente($usuario, $persona);
    }

    /**
     * @return array<string, mixed>
     */
    private function resumen(User $usuario, SolicitudVacaciones $v): array
    {
        return [
            'id' => "vacaciones:{$v->id}",
            'resource_id' => $v->id,
            'estado' => $v->estado->value,
            'colaborador' => $this->colaboradorResumen($v->personaSolicitante()),
            'fecha_inicio' => $v->fecha_inicio->toDateString(),
            'fecha_fin' => $v->fecha_fin->toDateString(),
            'dias_solicitados' => $v->dias_solicitados,
            'creado_en' => $v->created_at?->toIso8601String(),
            'acciones_permitidas' => $this->workflow->paraVacacion($usuario, $v)['acciones_permitidas'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function colaboradorResumen(?Colaborador $colaborador): array
    {
        if ($colaborador === null) {
            return ['id' => null, 'nombre' => null, 'numero_empleado' => null, 'puesto' => null, 'sucursal' => null];
        }

        return [
            'id' => $colaborador->id,
            'nombre' => $colaborador->nombreCompleto(),
            'numero_empleado' => $colaborador->numero_empleado,
            'puesto' => $colaborador->puesto?->nombre,
            'sucursal' => $colaborador->sucursalPrincipal?->nombre,
        ];
    }
}
