<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Controller;
use App\Models\SolicitudInterna;
use App\Models\SolicitudInternaHistorial;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\RhMobile\WorkflowService;
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

        $solicitud->load(['usuario:id,name,apellidos,numero_empleado,sucursal_principal_id,puesto_id', 'usuario.sucursalPrincipal:id,nombre', 'usuario.puesto:id,nombre', 'revisadoPor:id,name,apellidos', 'documentos', 'historial.usuario:id,name,apellidos']);

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

        return response()->json(['message' => 'Solicitud aprobada correctamente', 'data' => ['id' => $solicitud->id, 'estado' => $solicitud->estado->value]]);
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
            'numero_empleado' => $colaborador->numero_empleado,
            'puesto' => $colaborador->puesto?->nombre,
            'sucursal' => $colaborador->sucursalPrincipal?->nombre,
        ];
    }
}
