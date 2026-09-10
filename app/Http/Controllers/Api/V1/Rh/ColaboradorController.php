<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Controller;
use App\Models\SolicitudInterna;
use App\Models\SolicitudVacaciones;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Directorio de colaboradores para RH desde la app movil: datos basicos
 * acotados por alcance organizacional, nunca el expediente completo (para
 * eso existe App\Http\Controllers\Api\V1\Rh\ExpedienteController). Ver
 * seccion 12 del encargo movil.
 */
class ColaboradorController extends Controller
{
    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    public function index(Request $request): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.colaboradores.ver'), 403);

        $query = User::query()
            ->with(['sucursalPrincipal:id,nombre', 'departamento:id,nombre', 'puesto:id,nombre'])
            ->when($request->string('q')->toString(), function ($q, string $busqueda): void {
                $q->where(fn ($sub) => $sub->where('name', 'like', "%{$busqueda}%")
                    ->orWhere('apellidos', 'like', "%{$busqueda}%")
                    ->orWhere('numero_empleado', 'like', "%{$busqueda}%"));
            })
            ->when($request->integer('sucursal_id'), fn ($q, int $id) => $q->where('sucursal_principal_id', $id))
            ->when($request->integer('departamento_id'), fn ($q, int $id) => $q->where('departamento_id', $id))
            ->when($request->string('estatus')->toString(), fn ($q, string $estatus) => $q->where('estatus', $estatus));

        $query = $this->alcance->limitarUsuariosPorAlcance($query, $usuario);

        $colaboradores = $query->orderBy('name')->paginate((int) $request->integer('per_page', 15))->withQueryString();

        return response()->json([
            'data' => collect($colaboradores->items())->map(fn (User $c) => [
                'id' => $c->id,
                'nombre' => $c->nombreCompleto(),
                'numero_empleado' => $c->numero_empleado,
                'estatus' => $c->estatus->value,
                'sucursal' => $c->sucursalPrincipal?->nombre,
                'departamento' => $c->departamento?->nombre,
                'puesto' => $c->puesto?->nombre,
            ])->values(),
            'meta' => [
                'current_page' => $colaboradores->currentPage(),
                'per_page' => $colaboradores->perPage(),
                'total' => $colaboradores->total(),
            ],
        ]);
    }

    public function show(Request $request, User $colaborador): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.colaboradores.detalle'), 403);
        abort_unless($this->alcance->puedeVerUsuario($usuario, $colaborador), 404);

        $colaborador->loadMissing(['sucursalPrincipal:id,nombre', 'departamento:id,nombre', 'puesto:id,nombre']);

        return response()->json([
            'data' => [
                'id' => $colaborador->id,
                'nombre' => $colaborador->nombreCompleto(),
                'numero_empleado' => $colaborador->numero_empleado,
                'email' => $colaborador->email,
                'telefono' => $colaborador->telefono,
                'estatus' => $colaborador->estatus->value,
                'sucursal' => $colaborador->sucursalPrincipal?->nombre,
                'departamento' => $colaborador->departamento?->nombre,
                'puesto' => $colaborador->puesto?->nombre,
                'resumen' => [
                    'solicitudes_pendientes' => SolicitudInterna::query()->where('user_id', $colaborador->id)->whereIn('estado', ['enviada', 'en_revision'])->count(),
                    'vacaciones_pendientes' => SolicitudVacaciones::query()->where('user_id', $colaborador->id)->where('estado', 'pendiente')->count(),
                    'documentos_pendientes' => $colaborador->documentos()->whereIn('status', ['cargado', 'en_revision', 'cambio_solicitado'])->count(),
                ],
                'acciones_permitidas' => array_values(array_filter([
                    'ver',
                    $usuario->can('rh.expedientes.detalle') ? 'ver_expediente' : null,
                ])),
            ],
        ]);
    }
}
