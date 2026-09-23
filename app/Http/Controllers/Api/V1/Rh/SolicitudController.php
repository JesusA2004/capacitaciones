<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Enums\EstadoSolicitudInterna;
use App\Enums\TipoSolicitudInterna;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\ActualizarEstadoSolicitudInternaRequest;
use App\Models\Prestamo;
use App\Models\SolicitudAprobacion;
use App\Models\SolicitudInterna;
use App\Models\SolicitudInternaHistorial;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\RhMobile\WorkflowService;
use App\Services\Solicitudes\AprobacionJerarquicaService;
use App\Services\Solicitudes\SolicitudesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bandeja de solicitudes internas para RH/aprobadores desde la app movil.
 * Reutiliza App\Services\Solicitudes\SolicitudesService (misma logica que el
 * Portal RH web): este controlador solo autoriza, filtra y da forma al JSON.
 * Ver seccion 8 del encargo movil y docs/RH_MOBILE_API.md.
 */
class SolicitudController extends Controller
{
    public function __construct(
        private readonly SolicitudesService $solicitudes,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly WorkflowService $workflow,
        private readonly AprobacionJerarquicaService $aprobaciones,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.solicitudes.ver'), 403);

        $filtros = $request->only(['estado', 'tipo', 'sucursal_id', 'empresa_id', 'departamento_id']);
        if ($q = $request->string('q')->toString()) {
            $filtros['busqueda'] = $q;
        }

        $solicitudes = $this->solicitudes->paraRevision($usuario, $filtros);

        return response()->json([
            'data' => $solicitudes->getCollection()->map(fn (SolicitudInterna $s) => $this->resumen($usuario, $s))->values(),
            'meta' => [
                'current_page' => $solicitudes->currentPage(),
                'per_page' => $solicitudes->perPage(),
                'total' => $solicitudes->total(),
            ],
        ]);
    }

    public function show(Request $request, SolicitudInterna $solicitud): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.solicitudes.detalle'), 403);
        abort_unless($this->puedeVer($usuario, $solicitud), 403);

        $solicitud->load([
            'usuario:id,name,apellidos,colaborador_id',
            'usuario.colaborador:id,numero_empleado,sucursal_principal_id,puesto_id',
            'usuario.colaborador.sucursalPrincipal:id,nombre',
            'usuario.colaborador.puesto:id,nombre',
            'revisadoPor:id,name,apellidos',
            'documentos',
            'historial.usuario:id,name,apellidos',
        ]);

        $flujo = $this->workflow->paraSolicitud($usuario, $solicitud);

        return response()->json([
            'data' => [
                'id' => $solicitud->id,
                'folio' => $solicitud->folio,
                'tipo' => $solicitud->tipo->value,
                'estado' => $solicitud->estado->value,
                'colaborador' => $this->colaboradorResumen($solicitud->usuario),
                'fecha_inicio' => $solicitud->fecha_inicio?->toDateString(),
                'fecha_fin' => $solicitud->fecha_fin?->toDateString(),
                'motivo' => $solicitud->motivo,
                'motivo_rechazo' => $solicitud->motivo_rechazo,
                'adjuntos' => $solicitud->documentos->map(fn ($d) => [
                    'id' => $d->id,
                    'nombre' => $d->original_name,
                ])->values(),
                'monto_solicitado' => $solicitud->monto_solicitado !== null ? (float) $solicitud->monto_solicitado : null,
                'plazo_solicitado' => $solicitud->plazo_meses,
                'prestamo' => $solicitud->tipo === TipoSolicitudInterna::PrestamoInterno ? $this->prestamoDecision($usuario, $solicitud) : null,
                'acciones_permitidas' => $flujo['acciones_permitidas'],
                'workflow' => $flujo['workflow'],
                'historial' => $solicitud->historial->map(fn (SolicitudInternaHistorial $h) => [
                    'accion' => $h->accion,
                    'comentario' => $h->comentario,
                    'usuario' => $h->usuario?->nombreCompleto(),
                    'fecha' => $h->created_at->toIso8601String(),
                ])->values(),
            ],
        ]);
    }

    public function aprobar(Request $request, SolicitudInterna $solicitud): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.solicitudes.aprobar'), 403);
        abort_unless($this->puedeVer($usuario, $solicitud), 404);
        $this->validarPendiente($solicitud);

        $datos = $request->validate(['comentario' => ['nullable', 'string', 'max:1000']]);

        $solicitud = $this->solicitudes->aprobar($solicitud, $usuario, $datos['comentario'] ?? null);

        $mensaje = 'Solicitud aprobada correctamente';
        $resultadoDocumento = $this->solicitudes->ultimoResultadoDocumentoOficial();

        if ($resultadoDocumento !== null && $resultadoDocumento['aplica']) {
            $mensaje = $resultadoDocumento['motivo_error'] !== null
                ? "{$mensaje}. Documento oficial NO generado: {$resultadoDocumento['motivo_error']}."
                : "{$mensaje}. Documento oficial generado correctamente.";
        }

        return response()->json(['message' => $mensaje, 'data' => ['id' => $solicitud->id, 'estado' => $solicitud->estado->value]]);
    }

    public function rechazar(Request $request, SolicitudInterna $solicitud): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.solicitudes.rechazar'), 403);
        abort_unless($this->puedeVer($usuario, $solicitud), 404);
        $this->validarPendiente($solicitud);

        $datos = $request->validate(['motivo' => ['required', 'string', 'max:1000']]);

        $solicitud = $this->solicitudes->rechazar($solicitud, $usuario, $datos['motivo']);

        return response()->json(['message' => 'Solicitud rechazada correctamente', 'data' => ['id' => $solicitud->id, 'estado' => $solicitud->estado->value]]);
    }

    public function correccion(Request $request, SolicitudInterna $solicitud): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.solicitudes.correccion'), 403);
        abort_unless($this->puedeVer($usuario, $solicitud), 404);
        $this->validarPendiente($solicitud);

        $datos = $request->validate(['motivo' => ['required', 'string', 'max:1000']]);

        $solicitud = $this->solicitudes->requerirCorreccion($solicitud, $usuario, $datos['motivo']);

        return response()->json(['message' => 'Se pidió corrección al colaborador', 'data' => ['id' => $solicitud->id, 'estado' => $solicitud->estado->value]]);
    }

    /**
     * Cambio de estado unificado (mismo destino que el tablero Kanban web,
     * ver Rh\SolicitudController::actualizarEstado): traduce el estado
     * destino a la misma policy que ya usan aprobar()/rechazar()/
     * correccion() de arriba, y reutiliza
     * SolicitudesService::moverEnTablero() en vez de duplicar la lógica de
     * cambiarEstado() para cada acción.
     */
    public function actualizarEstado(ActualizarEstadoSolicitudInternaRequest $request, SolicitudInterna $solicitud): JsonResponse
    {
        $usuario = $request->user();
        $nuevoEstado = EstadoSolicitudInterna::from($request->validated('estado'));

        $habilidad = match ($nuevoEstado) {
            EstadoSolicitudInterna::EnRevision, EstadoSolicitudInterna::RequiereCorreccion => 'revisar',
            EstadoSolicitudInterna::Aprobada => 'aprobar',
            EstadoSolicitudInterna::Rechazada => 'rechazar',
            EstadoSolicitudInterna::Cerrada => 'cerrar',
            default => abort(422, 'Ese estado no se puede asignar desde el tablero.'),
        };

        abort_unless($usuario->can($habilidad, $solicitud), 403);

        $comentario = $request->validated('motivo_rechazo') ?? $request->validated('comentario');

        $solicitud = $this->solicitudes->moverEnTablero($solicitud, $usuario, $nuevoEstado, $comentario);

        $mensaje = 'Solicitud movida a '.$nuevoEstado->etiqueta().'.';
        $resultadoDocumento = $this->solicitudes->ultimoResultadoDocumentoOficial();

        if ($resultadoDocumento !== null && $resultadoDocumento['aplica']) {
            $mensaje = $resultadoDocumento['motivo_error'] !== null
                ? "{$mensaje} Documento oficial NO generado: {$resultadoDocumento['motivo_error']}."
                : "{$mensaje} Documento oficial generado correctamente.";
        }

        return response()->json(['message' => $mensaje, 'data' => ['id' => $solicitud->id, 'estado' => $solicitud->estado->value]]);
    }

    /**
     * Lo necesario para decidir un préstamo SIN autorizar a ciegas: lo
     * solicitado, el visto bueno del jefe inmediato (misma regla que
     * SolicitudesService::cambiarEstado) y si esta cuenta puede autorizar
     * (PrestamoPolicy::autorizarSolicitud). La app solo pinta estos datos;
     * el backend sigue siendo la autoridad al autorizar (422/403).
     *
     * @return array<string, mixed>
     */
    private function prestamoDecision(User $usuario, SolicitudInterna $solicitud): array
    {
        $requiereVistoBueno = $this->aprobaciones->requiereVistoBuenoJefe($solicitud);
        $decision = $this->aprobaciones->decisionJefe($solicitud);
        $decision?->loadMissing('usuario.colaborador');
        $pendiente = in_array($solicitud->estado, [EstadoSolicitudInterna::Enviada, EstadoSolicitudInterna::EnRevision], true);
        $vistoBuenoCumplido = ! $requiereVistoBueno || $decision?->decision === SolicitudAprobacion::DECISION_APROBADO;
        $puedeDecidir = $pendiente && $usuario->can('autorizarSolicitud', [Prestamo::class, $solicitud]);

        return [
            'monto_solicitado' => $solicitud->monto_solicitado !== null ? (float) $solicitud->monto_solicitado : null,
            'plazo_solicitado' => $solicitud->plazo_meses,
            'visto_bueno' => [
                'requerido' => $requiereVistoBueno,
                'estado' => $decision->decision ?? ($requiereVistoBueno ? 'pendiente' : 'no_aplica'),
                'jefe' => $decision?->usuario?->nombreCompleto(),
                'comentario' => $decision?->comentario,
                'fecha' => $decision?->created_at?->toIso8601String(),
            ],
            'prestamo_id' => $solicitud->prestamo()->value('id'),
            'puede_autorizar' => $puedeDecidir && $vistoBuenoCumplido && $solicitud->monto_solicitado !== null,
            'puede_rechazar' => $puedeDecidir,
        ];
    }

    private function validarPendiente(SolicitudInterna $solicitud): void
    {
        abort_if($solicitud->estado->esFinal(), 422, 'Esta solicitud ya no admite esta acción.');
    }

    private function puedeVer(User $usuario, SolicitudInterna $solicitud): bool
    {
        $solicitud->loadMissing('usuario');

        return $this->alcance->tieneAlcanceGlobal($usuario) || $this->alcance->puedeVerUsuario($usuario, $solicitud->usuario);
    }

    /**
     * @return array<string, mixed>
     */
    private function resumen(User $usuario, SolicitudInterna $s): array
    {
        return [
            'id' => "solicitud:{$s->id}",
            'resource_id' => $s->id,
            'folio' => $s->folio,
            'tipo' => $s->tipo->value,
            'titulo' => $s->tipo->etiqueta(),
            'estado' => $s->estado->value,
            'colaborador' => $this->colaboradorResumen($s->usuario),
            'resumen' => $s->motivo,
            'creado_en' => $s->created_at?->toIso8601String(),
            'acciones_permitidas' => $this->workflow->paraSolicitud($usuario, $s)['acciones_permitidas'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function colaboradorResumen(?User $colaborador): array
    {
        if ($colaborador === null) {
            return ['id' => null, 'nombre' => null, 'numero_empleado' => null, 'puesto' => null, 'sucursal' => null];
        }

        return [
            'id' => $colaborador->id,
            'nombre' => $colaborador->nombreCompleto(),
            'numero_empleado' => $colaborador->colaborador?->numero_empleado,
            'puesto' => $colaborador->colaborador?->puesto?->nombre,
            'sucursal' => $colaborador->colaborador?->sucursalPrincipal?->nombre,
        ];
    }
}
