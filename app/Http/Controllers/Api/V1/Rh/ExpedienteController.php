<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Incorporacion\IncorporacionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Expedientes/incorporacion para RH desde la app movil (Sanctum, sin
 * sesion web). A diferencia de App\Http\Controllers\Api\V1\IncorporacionController
 * (solo el propio colaborador, vista angosta), aqui RH ve expedientes
 * completos, pero solo dentro de su alcance organizacional
 * (AlcanceOrganizacionalService) y con los permisos rh.expedientes.* — ver
 * docs/API_MOVIL.md.
 */
class ExpedienteController extends Controller
{
    private const POR_PAGINA = 20;

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly IncorporacionService $incorporacion,
        private readonly DocumentoStorageService $storage,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.expedientes.ver'), 403);

        $colaboradores = $this->queryFiltrada($request)->orderBy('name')->get();

        $filas = $colaboradores->map(fn (User $c) => [
            'colaborador' => $c,
            'estado' => $this->incorporacion->estado($c),
        ]);

        if ($estado = $request->string('estado')->toString()) {
            $filas = $filas->filter(fn (array $fila) => $fila['estado'] === $estado)->values();
        }

        $total = $filas->count();
        $pagina = max(1, $request->integer('page', 1));

        $data = $filas->forPage($pagina, self::POR_PAGINA)->map(function (array $fila) {
            $c = $fila['colaborador'];

            return [
                'id' => $c->id,
                'nombre' => $c->nombreCompleto(),
                'correo' => $c->email,
                'numero_empleado' => $c->numero_empleado,
                'empresa' => $c->sucursalPrincipal?->empresa?->nombre,
                'sucursal' => $c->sucursalPrincipal?->nombre,
                'departamento' => $c->departamento?->nombre,
                'estatus_usuario' => $c->estatus->value,
                'estado_incorporacion' => $fila['estado'],
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $pagina,
                'per_page' => self::POR_PAGINA,
                'total' => $total,
                'last_page' => (int) max(1, ceil($total / self::POR_PAGINA)),
            ],
        ]);
    }

    public function show(Request $request, User $colaborador): JsonResponse
    {
        abort_unless($this->puedeVerDetalle($request->user(), $colaborador), 403);

        $tipos = $this->incorporacion->tiposDocumento();
        $documentos = $this->incorporacion->detalleParaRh($colaborador);

        $colaborador->loadMissing([
            'sucursalPrincipal:id,nombre,empresa_id',
            'sucursalPrincipal.empresa:id,nombre',
            'departamento:id,nombre',
            'puesto:id,nombre',
        ]);

        return response()->json([
            'colaborador' => [
                'id' => $colaborador->id,
                'nombre' => $colaborador->name,
                'apellidos' => $colaborador->apellidos,
                'numero_empleado' => $colaborador->numero_empleado,
                'correo' => $colaborador->email,
                'telefono' => $colaborador->telefono,
                'estatus' => $colaborador->estatus->value,
                'fecha_ingreso' => $colaborador->fecha_ingreso?->toDateString(),
                'empresa' => $colaborador->sucursalPrincipal?->empresa?->nombre,
                'sucursal' => $colaborador->sucursalPrincipal?->nombre,
                'departamento' => $colaborador->departamento?->nombre,
                'puesto' => $colaborador->puesto?->nombre,
            ],
            'estado_incorporacion' => $this->incorporacion->estado($colaborador),
            'incorporacion_decidida_por' => $colaborador->incorporacionDecididaPor?->nombreCompleto(),
            'incorporacion_decidida_en' => $colaborador->incorporacion_decidida_en?->toIso8601String(),
            'incorporacion_motivo_rechazo' => $colaborador->incorporacion_motivo_rechazo,
            'documentos' => $documentos,
            'documentos_totales' => $tipos->count(),
        ]);
    }

    /**
     * Sirve el documento en streaming, sin exponer nunca la ruta fisica del
     * disco NAS al cliente: el navegador/app solo conoce el id numerico de
     * `documento` y llega aqui siempre autenticado con Bearer token.
     */
    public function verDocumento(Request $request, User $colaborador, EmployeeDocument $documento): StreamedResponse
    {
        abort_unless($this->puedeVerDetalle($request->user(), $colaborador), 403);
        abort_unless($request->user()->can('rh.expedientes.documentos.ver'), 403);
        abort_unless($documento->user_id === $colaborador->id, 404);

        return $this->storage->respuesta($documento->path, [
            'Content-Type' => $documento->mime ?? 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.$documento->original_name.'"',
        ]);
    }

    public function aprobarDocumento(Request $request, User $colaborador, EmployeeDocument $documento): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.expedientes.documentos.aprobar'), 403);
        abort_unless($this->puedeVerDetalle($usuario, $colaborador), 403);
        abort_unless($documento->user_id === $colaborador->id, 404);

        $datos = $request->validate(['comentario' => ['nullable', 'string', 'max:500']]);

        $this->incorporacion->aprobarDocumento($documento, $usuario, $datos['comentario'] ?? null);

        return response()->json(['message' => 'Documento aprobado.']);
    }

    public function rechazarDocumento(Request $request, User $colaborador, EmployeeDocument $documento): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.expedientes.documentos.rechazar'), 403);
        abort_unless($this->puedeVerDetalle($usuario, $colaborador), 403);
        abort_unless($documento->user_id === $colaborador->id, 404);

        $datos = $request->validate(['motivo' => ['required', 'string', 'max:500']]);

        $this->incorporacion->rechazarDocumento($documento, $usuario, $datos['motivo']);

        return response()->json(['message' => 'Documento rechazado.']);
    }

    public function autorizarCambioDocumento(Request $request, User $colaborador, EmployeeDocument $documento): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.expedientes.documentos.autorizar-cambio'), 403);
        abort_unless($this->puedeVerDetalle($usuario, $colaborador), 403);
        abort_unless($documento->user_id === $colaborador->id, 404);

        try {
            $this->incorporacion->autorizarCambio($documento, $usuario);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['documento' => $e->getMessage()]);
        }

        return response()->json(['message' => 'Cambio autorizado. El colaborador ya puede subir la nueva version.']);
    }

    public function aprobarIncorporacion(Request $request, User $colaborador): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.expedientes.incorporacion.aprobar'), 403);
        abort_unless($this->puedeVerDetalle($usuario, $colaborador), 403);

        try {
            $this->incorporacion->aprobarIncorporacion($colaborador, $usuario);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['colaborador' => $e->getMessage()]);
        }

        return response()->json(['message' => 'Incorporación aprobada: el colaborador quedó activo.']);
    }

    public function rechazarIncorporacion(Request $request, User $colaborador): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.expedientes.incorporacion.rechazar'), 403);
        abort_unless($this->puedeVerDetalle($usuario, $colaborador), 403);

        $datos = $request->validate(['motivo' => ['required', 'string', 'max:500']]);

        $this->incorporacion->rechazarIncorporacion($colaborador, $usuario, $datos['motivo']);

        return response()->json(['message' => 'Incorporación rechazada.']);
    }

    private function puedeVerDetalle(User $usuario, User $colaborador): bool
    {
        return $usuario->can('rh.expedientes.detalle') && $this->alcance->puedeVerUsuario($usuario, $colaborador);
    }

    /**
     * @return Builder<User>
     */
    private function queryFiltrada(Request $request): Builder
    {
        $usuario = $request->user();

        return User::query()
            ->tap(fn ($query) => $this->alcance->limitarUsuariosPorAlcance($query, $usuario))
            ->with(['sucursalPrincipal:id,nombre,empresa_id', 'sucursalPrincipal.empresa:id,nombre', 'departamento:id,nombre'])
            ->when($request->string('busqueda')->toString(), function ($query, string $busqueda) {
                $query->where(function ($sub) use ($busqueda) {
                    $sub->where('name', 'like', "%{$busqueda}%")
                        ->orWhere('apellidos', 'like', "%{$busqueda}%")
                        ->orWhere('email', 'like', "%{$busqueda}%")
                        ->orWhere('numero_empleado', 'like', "%{$busqueda}%");
                });
            })
            ->when($request->integer('empresa_id'), fn ($query, int $id) => $query->whereHas('sucursalPrincipal', fn ($sub) => $sub->where('empresa_id', $id)))
            ->when($request->integer('sucursal_id'), fn ($query, int $id) => $query->where('sucursal_principal_id', $id))
            ->when($request->integer('departamento_id'), fn ($query, int $id) => $query->where('departamento_id', $id));
    }
}
