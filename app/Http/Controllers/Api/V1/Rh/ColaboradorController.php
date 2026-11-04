<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Enums\TipoSolicitudInterna;
use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\SolicitudInterna;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Colaboradores\FotoColaboradorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Directorio de colaboradores para RH desde la app movil: datos basicos
 * acotados por alcance organizacional, nunca el expediente completo (para
 * eso existe App\Http\Controllers\Api\V1\Rh\ExpedienteController). Ver
 * seccion 12 del encargo movil.
 */
class ColaboradorController extends Controller
{
    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly FotoColaboradorService $fotos,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.colaboradores.ver'), 403);

        $query = Colaborador::query()
            ->with(['sucursalPrincipal:id,nombre', 'departamento:id,nombre', 'puesto:id,nombre'])
            // Buscador único (nombre, número, puesto, sucursal…), aditivo para la app.
            ->when($request->string('q')->toString(), fn ($q, string $busqueda) => $q->buscar($busqueda))
            ->when($request->integer('sucursal_id'), fn ($q, int $id) => $q->where('sucursal_principal_id', $id))
            ->when($request->integer('departamento_id'), fn ($q, int $id) => $q->where('departamento_id', $id))
            ->when($request->string('estatus')->toString(), fn ($q, string $estatus) => $q->where('estatus', $estatus));

        // Mismo alcance que el detalle (módulo «Expedientes», puedeVerExpediente):
        // la lista nunca muestra a alguien cuyo detalle daría 404.
        $query = $this->alcance->limitarExpedientesPorAlcance($query, $usuario);

        $colaboradores = $query->orderBy('name')->paginate((int) $request->integer('per_page', 15))->withQueryString();

        return response()->json([
            'data' => collect($colaboradores->items())->map(fn (Colaborador $c) => [
                'id' => $c->id,
                'nombre' => $c->nombreCompleto(),
                'numero_empleado' => $c->numero_empleado,
                'estatus' => $c->estatus->value,
                'sucursal' => $c->sucursalPrincipal?->nombre,
                'departamento' => $c->departamento?->nombre,
                'puesto' => $c->puesto?->nombre,
                // URL que la app abre con su Bearer token (null = iniciales).
                'foto_url' => $this->fotos->urlApiPara($c, $usuario),
            ])->values(),
            'meta' => [
                'current_page' => $colaboradores->currentPage(),
                'per_page' => $colaboradores->perPage(),
                'total' => $colaboradores->total(),
            ],
        ]);
    }

    public function show(Request $request, Colaborador $colaborador): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.colaboradores.detalle'), 403);
        abort_unless($this->alcance->puedeVerExpediente($usuario, $colaborador), 404, 'Esta persona no existe o está fuera de tu alcance.');

        $colaborador->loadMissing(['sucursalPrincipal:id,nombre', 'departamento:id,nombre', 'puesto:id,nombre']);

        return response()->json([
            'data' => [
                'id' => $colaborador->id,
                'nombre' => $colaborador->nombreCompleto(),
                'numero_empleado' => $colaborador->numero_empleado,
                'email' => $colaborador->correo_personal,
                'telefono' => $colaborador->telefono,
                'contacto_emergencia' => [
                    'nombre' => $colaborador->contacto_emergencia_nombre,
                    'telefono' => $colaborador->contacto_emergencia_telefono,
                ],
                'estatus' => $colaborador->estatus->value,
                'sucursal' => $colaborador->sucursalPrincipal?->nombre,
                'departamento' => $colaborador->departamento?->nombre,
                'puesto' => $colaborador->puesto?->nombre,
                'foto_url' => $this->fotos->urlRhApi($colaborador),
                'resumen' => [
                    'solicitudes_pendientes' => SolicitudInterna::query()->where('colaborador_id', $colaborador->id)->whereIn('estado', ['enviada', 'en_revision'])->count(),
                    // Bug corregido: antes contaba solo la tabla legacy
                    // `solicitudes_vacaciones` (`SolicitudVacaciones`), que
                    // ya no recibe las solicitudes de vacaciones creadas por
                    // el flujo unificado actual (tipo=vacaciones en
                    // `solicitudes_internas`) — mostraba 0 pendientes aunque
                    // sí hubiera una solicitud real esperando revisión.
                    'vacaciones_pendientes' => SolicitudInterna::query()->where('colaborador_id', $colaborador->id)->where('tipo', TipoSolicitudInterna::Vacaciones)->whereIn('estado', ['enviada', 'en_revision'])->count(),
                    'documentos_pendientes' => $colaborador->documentos()->whereIn('status', ['cargado', 'en_revision', 'cambio_solicitado'])->count(),
                ],
                'acciones_permitidas' => array_filter([
                    'ver',
                    $usuario->can('rh.expedientes.detalle') ? 'ver_expediente' : null,
                ]),
            ],
        ]);
    }

    /**
     * Foto oficial de un colaborador para RH en la app (Bearer token): solo
     * dentro del alcance de expedientes de quien la pide (403 fuera de él).
     * Nunca expone `foto_path`.
     */
    public function foto(Request $request, Colaborador $colaborador): StreamedResponse
    {
        abort_unless($this->alcance->puedeVerExpediente($request->user(), $colaborador), 403, 'Esta persona está fuera de tu alcance.');

        return $this->fotos->respuestaOficial($colaborador);
    }
}
