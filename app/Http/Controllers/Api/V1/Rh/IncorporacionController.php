<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Enums\EstadoUsuario;
use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Expedientes\ExpedienteService;
use App\Services\Incorporacion\IncorporacionService;
use App\Services\RhMobile\WorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Bandeja de incorporaciones para RH desde la app movil. Mapea internamente
 * a App\Services\Incorporacion\IncorporacionService::aprobarIncorporacion/
 * rechazarIncorporacion — la misma logica que
 * App\Http\Controllers\Api\V1\Rh\ExpedienteController::aprobarIncorporacion/
 * rechazarIncorporacion (que se conservan para compatibilidad), nunca
 * duplicada. Ver seccion 11 del encargo movil.
 *
 * Categorias de `estado` en el listado (calculadas, no persistidas):
 * pendiente|en_revision (incorporacion_decision null, expediente incompleto
 * o en revision), completo (todos los documentos obligatorios aprobados,
 * lista para decision final), aprobada|rechazada (decision ya tomada).
 */
class IncorporacionController extends Controller
{
    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly IncorporacionService $incorporacion,
        private readonly WorkflowService $workflow,
        private readonly ExpedienteService $expediente,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.incorporaciones.ver'), 403);

        $query = Colaborador::query()
            ->where(fn ($q) => $q->where('estatus', EstadoUsuario::EnIncorporacion->value)->orWhereNotNull('incorporacion_decision'))
            ->with(['sucursalPrincipal:id,nombre', 'puesto:id,nombre']);

        $query = $this->alcance->limitarColaboradoresPorAlcance($query, $usuario);

        if ($busqueda = $request->string('q')->toString()) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$busqueda}%")->orWhere('numero_empleado', 'like', "%{$busqueda}%"));
        }

        $candidatos = $query->orderByDesc('created_at')->get();

        $filas = $candidatos->map(fn (Colaborador $c) => [
            'colaborador' => $c,
            'estado' => $this->estadoIncorporacion($c),
        ]);

        if ($estado = $request->string('estado')->toString()) {
            $filas = $filas->filter(fn (array $f) => $f['estado'] === $estado)->values();
        }

        $porPagina = max(1, (int) $request->integer('per_page', 15));
        $pagina = max(1, (int) $request->integer('page', 1));
        $total = $filas->count();

        $data = $filas->forPage($pagina, $porPagina)->map(fn (array $f) => [
            'id' => "incorporacion:{$f['colaborador']->id}",
            'resource_id' => $f['colaborador']->id,
            'estado' => $f['estado'],
            'colaborador' => $this->colaboradorResumen($f['colaborador']),
            'creado_en' => $f['colaborador']->created_at?->toIso8601String(),
            'acciones_permitidas' => $this->workflow->paraIncorporacion($usuario, $f['colaborador'], $f['estado'])['acciones_permitidas'],
        ])->values();

        return response()->json([
            'data' => $data,
            'meta' => ['current_page' => $pagina, 'per_page' => $porPagina, 'total' => $total],
        ]);
    }

    public function show(Request $request, Colaborador $colaborador): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.incorporaciones.detalle'), 403);
        abort_unless($this->alcance->puedeVerExpediente($usuario, $colaborador), 403);

        $estadoGeneral = $this->estadoIncorporacion($colaborador);
        $flujo = $this->workflow->paraIncorporacion($usuario, $colaborador, $estadoGeneral);
        $documentos = $this->incorporacion->detalleParaRh($colaborador);

        return response()->json([
            'data' => [
                'colaborador' => $this->colaboradorResumen($colaborador),
                'estado' => $estadoGeneral,
                'progreso' => $this->incorporacion->progreso($this->incorporacion->tiposDocumento(), $this->expediente->documentosVigentes($colaborador)),
                'documentos' => $documentos,
                'documentos_faltantes' => collect($documentos)->where('documento_id', null)->values(),
                'documentos_rechazados' => collect($documentos)->whereIn('estado', ['rechazado', 'requiere_correccion'])->values(),
                'motivo_rechazo' => $colaborador->incorporacion_motivo_rechazo,
                'acciones_permitidas' => $flujo['acciones_permitidas'],
                'workflow' => $flujo['workflow'],
                'historial' => [],
            ],
        ]);
    }

    public function aprobar(Request $request, Colaborador $colaborador): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.incorporaciones.aprobar'), 403);
        abort_unless($this->alcance->puedeVerExpediente($usuario, $colaborador), 404);

        try {
            $this->incorporacion->aprobarIncorporacion($colaborador, $usuario);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['colaborador' => $e->getMessage()]);
        }

        return response()->json(['message' => 'Incorporación aprobada correctamente', 'data' => ['id' => $colaborador->id, 'estado' => 'aprobada']]);
    }

    public function rechazar(Request $request, Colaborador $colaborador): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.incorporaciones.rechazar'), 403);
        abort_unless($this->alcance->puedeVerExpediente($usuario, $colaborador), 404);

        $datos = $request->validate(['motivo' => ['required', 'string', 'max:500']]);

        $this->incorporacion->rechazarIncorporacion($colaborador, $usuario, $datos['motivo']);

        return response()->json(['message' => 'Incorporación rechazada correctamente', 'data' => ['id' => $colaborador->id, 'estado' => 'rechazada']]);
    }

    private function estadoIncorporacion(Colaborador $colaborador): string
    {
        if ($colaborador->incorporacion_decision === 'aprobado') {
            return 'aprobada';
        }

        if ($colaborador->incorporacion_decision === 'rechazado') {
            return 'rechazada';
        }

        return $this->incorporacion->estado($colaborador);
    }

    /**
     * @return array<string, mixed>
     */
    private function colaboradorResumen(Colaborador $colaborador): array
    {
        $colaborador->loadMissing(['sucursalPrincipal:id,nombre', 'puesto:id,nombre']);

        return [
            'id' => $colaborador->id,
            'nombre' => $colaborador->nombreCompleto(),
            'numero_empleado' => $colaborador->numero_empleado,
            'puesto' => $colaborador->puesto?->nombre,
            'sucursal' => $colaborador->sucursalPrincipal?->nombre,
        ];
    }
}
