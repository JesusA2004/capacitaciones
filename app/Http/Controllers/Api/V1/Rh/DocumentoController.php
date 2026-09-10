<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Documentos\DocumentExtractionService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Incorporacion\IncorporacionService;
use App\Services\RhMobile\WorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bandeja de documentos de expediente para RH desde la app movil. Reutiliza
 * App\Services\Incorporacion\IncorporacionService::aprobarDocumento/
 * rechazarDocumento (misma logica que App\Http\Controllers\Api\V1\Rh\ExpedienteController)
 * y App\Services\Expedientes\DocumentoStorageService para servir el archivo
 * sin exponer nunca la ruta fisica del NAS. Ver seccion 10 del encargo
 * movil.
 */
class DocumentoController extends Controller
{
    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly IncorporacionService $incorporacion,
        private readonly DocumentoStorageService $storage,
        private readonly WorkflowService $workflow,
        private readonly DocumentExtractionService $extraccion,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.documentos.ver'), 403);

        $query = EmployeeDocument::query()
            ->with(['usuario:id,name,apellidos,numero_empleado,sucursal_principal_id', 'usuario.sucursalPrincipal:id,nombre', 'tipo:id,nombre'])
            ->tap(fn ($q) => $this->alcance->tieneAlcanceGlobal($usuario)
                ? $q
                : $q->whereIn('user_id', $this->alcance->limitarUsuariosPorAlcance(User::query(), $usuario)->pluck('id')))
            ->when($request->string('estado')->toString(), fn ($q, string $estado) => $q->where('status', $estado))
            ->when($request->integer('sucursal_id'), fn ($q, int $id) => $q->whereHas('usuario', fn ($sub) => $sub->where('sucursal_principal_id', $id)))
            ->when($request->string('q')->toString(), function ($q, string $busqueda): void {
                $q->whereHas('usuario', fn ($sub) => $sub->where('name', 'like', "%{$busqueda}%")->orWhere('numero_empleado', 'like', "%{$busqueda}%"));
            });

        $documentos = $query->orderByDesc('created_at')->paginate((int) $request->integer('per_page', 15))->withQueryString();

        return response()->json([
            'data' => collect($documentos->items())->map(fn (EmployeeDocument $d) => $this->resumen($usuario, $d))->values(),
            'meta' => [
                'current_page' => $documentos->currentPage(),
                'per_page' => $documentos->perPage(),
                'total' => $documentos->total(),
            ],
        ]);
    }

    public function show(Request $request, EmployeeDocument $documento): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.documentos.detalle'), 403);
        abort_unless($this->puedeVer($usuario, $documento), 403);

        $documento->loadMissing(['usuario:id,name,apellidos,numero_empleado,sucursal_principal_id', 'usuario.sucursalPrincipal:id,nombre', 'tipo:id,nombre', 'subidoPor:id,name,apellidos', 'revisadoPor:id,name,apellidos']);

        $flujo = $this->workflow->paraDocumento($usuario, $documento);

        return response()->json([
            'data' => [
                'id' => $documento->id,
                'tipo_documento' => $documento->tipo?->nombre,
                'estado' => $documento->status->value,
                'version' => $documento->version,
                'colaborador' => $this->colaboradorResumen($documento->usuario),
                'nombre_original' => $documento->original_name,
                'motivo_rechazo' => $documento->rejection_reason,
                'comentarios' => $documento->comments,
                'subido_por' => $documento->subidoPor?->nombreCompleto(),
                'fecha_subida' => $documento->created_at?->toIso8601String(),
                'fecha_revision' => $documento->reviewed_at?->toIso8601String(),
                'acciones_permitidas' => $flujo['acciones_permitidas'],
                'workflow' => $flujo['workflow'],
                'historial' => [],
            ],
        ]);
    }

    /**
     * Sirve el documento en streaming: la app nunca conoce disk/path
     * fisico, solo el id numerico (ver App\Services\Expedientes\DocumentoStorageService).
     */
    public function ver(Request $request, EmployeeDocument $documento): StreamedResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.documentos.ver_archivo'), 403);
        abort_unless($this->puedeVer($usuario, $documento), 403);

        return $this->storage->respuesta($documento->path, [
            'Content-Type' => $documento->mime ?? 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.$documento->original_name.'"',
        ]);
    }

    public function aprobar(Request $request, EmployeeDocument $documento): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.documentos.aprobar'), 403);
        abort_unless($this->puedeVer($usuario, $documento), 404);

        $datos = $request->validate(['comentario' => ['nullable', 'string', 'max:500']]);

        $this->incorporacion->aprobarDocumento($documento, $usuario, $datos['comentario'] ?? null);

        return response()->json(['message' => 'Documento aprobado correctamente', 'data' => ['id' => $documento->id, 'estado' => $documento->fresh()->status->value]]);
    }

    public function rechazar(Request $request, EmployeeDocument $documento): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.documentos.rechazar'), 403);
        abort_unless($this->puedeVer($usuario, $documento), 404);

        $datos = $request->validate(['motivo' => ['required', 'string', 'max:500']]);

        $this->incorporacion->rechazarDocumento($documento, $usuario, $datos['motivo']);

        return response()->json(['message' => 'Documento rechazado correctamente', 'data' => ['id' => $documento->id, 'estado' => $documento->fresh()->status->value]]);
    }

    /**
     * Sugerencias de datos detectados automaticamente en este documento
     * (docs/DOCUMENT_EXTRACTION.md). `extraccion: null` si el tipo no es
     * elegible o el job aun no corrio; nunca 404 por eso.
     */
    public function extraccion(Request $request, EmployeeDocument $documento): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.documentos.extraccion.ver'), 403);
        abort_unless($this->puedeVer($usuario, $documento), 404);

        return response()->json([
            'data' => [
                'elegible' => DocumentExtractionService::tipoElegible($documento->tipo->clave),
                'extraccion' => $documento->extraccion,
            ],
        ]);
    }

    public function aplicarExtraccion(Request $request, EmployeeDocument $documento): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.documentos.extraccion.aplicar'), 403);
        abort_unless($this->puedeVer($usuario, $documento), 404);

        $datos = $request->validate([
            'valores' => ['required', 'array', 'min:1'],
            'valores.curp' => ['sometimes', 'string', 'max:18'],
            'valores.rfc' => ['sometimes', 'string', 'max:13'],
            'valores.nss' => ['sometimes', 'string', 'max:11'],
            'valores.fecha_nacimiento' => ['sometimes', 'date_format:d/m/Y'],
        ]);

        $extraccion = $documento->extraccion ?? abort(404, 'Este documento no tiene una extracción registrada.');
        $this->extraccion->aplicar($extraccion, $datos['valores'], $usuario);

        return response()->json(['message' => 'Datos aplicados al colaborador.']);
    }

    public function ignorarExtraccion(Request $request, EmployeeDocument $documento): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.documentos.extraccion.ignorar'), 403);
        abort_unless($this->puedeVer($usuario, $documento), 404);

        $extraccion = $documento->extraccion ?? abort(404, 'Este documento no tiene una extracción registrada.');
        $this->extraccion->ignorar($extraccion, $usuario);

        return response()->json(['message' => 'Sugerencias descartadas.']);
    }

    private function puedeVer(User $usuario, EmployeeDocument $documento): bool
    {
        $documento->loadMissing('usuario');

        return $this->alcance->tieneAlcanceGlobal($usuario) || $this->alcance->puedeVerUsuario($usuario, $documento->usuario);
    }

    /**
     * @return array<string, mixed>
     */
    private function resumen(User $usuario, EmployeeDocument $d): array
    {
        return [
            'id' => "documento:{$d->id}",
            'resource_id' => $d->id,
            'tipo_documento' => $d->tipo?->nombre,
            'estado' => $d->status->value,
            'colaborador' => $this->colaboradorResumen($d->usuario),
            'fecha_subida' => $d->created_at?->toIso8601String(),
            'acciones_permitidas' => $this->workflow->paraDocumento($usuario, $d)['acciones_permitidas'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function colaboradorResumen(?User $colaborador): array
    {
        if ($colaborador === null) {
            return ['id' => null, 'nombre' => null, 'numero_empleado' => null, 'sucursal' => null];
        }

        return [
            'id' => $colaborador->id,
            'nombre' => $colaborador->nombreCompleto(),
            'numero_empleado' => $colaborador->numero_empleado,
            'sucursal' => $colaborador->sucursalPrincipal?->nombre,
        ];
    }
}
