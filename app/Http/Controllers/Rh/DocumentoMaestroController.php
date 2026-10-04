<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\DocumentTemplate;
use App\Services\DocumentosMaestros\AlmacenMaestrosService;
use App\Services\DocumentosMaestros\DocumentosMaestrosAdminService;
use App\Services\DocumentosMaestros\ImportadorFormatosJuridicosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Administración → Documentos maestros. Solo administración (cargar,
 * versionar, activar, probar); RH no opera aquí los documentos de cada
 * persona — eso vive en cada proceso.
 */
class DocumentoMaestroController extends Controller
{
    public const PERMISO = 'plantillas_documentales.administrar';

    public function __construct(
        private readonly DocumentosMaestrosAdminService $admin,
        private readonly ImportadorFormatosJuridicosService $importador,
        private readonly AlmacenMaestrosService $almacen,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        abort_unless($request->user()?->can(self::PERMISO), 403);

        return Inertia::render('Rh/DocumentosMaestros/Index', [
            'masters' => $this->admin->listar(),
            'grupos' => config('documentos_maestros.grupos'),
        ]);
    }

    public function show(Request $request, DocumentTemplate $master): JsonResponse
    {
        abort_unless($request->user()?->can(self::PERMISO), 403);
        abort_if($master->estado_master === null, 404);

        return response()->json(['data' => $this->admin->detalle($master)]);
    }

    public function cargarVersion(Request $request, string $familia): JsonResponse
    {
        abort_unless($request->user()?->can(self::PERMISO), 403);
        $request->validate(['archivo' => ['required', 'file', 'max:20480', 'mimes:docx,pdf']]);
        $archivo = $request->file('archivo');
        abort_unless($archivo instanceof UploadedFile, 422);

        $master = $this->importador->cargarNuevaVersion($familia, (string) file_get_contents($archivo->getRealPath()), $archivo->getClientOriginalName(), $request->user());

        return response()->json([
            'message' => $master->estado_master === 'listo'
                ? sprintf('Versión %d cargada y preparada. Pruébala con un colaborador y actívala.', $master->version)
                : sprintf('Versión %d cargada con pendientes: revisa el reporte de campos antes de activarla.', $master->version),
            'data' => $this->admin->detalle($master),
        ], 201);
    }

    public function activar(Request $request, DocumentTemplate $master): JsonResponse
    {
        abort_unless($request->user()?->can(self::PERMISO), 403);

        return response()->json(['message' => 'Versión activada.', 'data' => $this->admin->detalle($this->importador->activar($master, $request->user()))]);
    }

    public function desactivar(Request $request, DocumentTemplate $master): JsonResponse
    {
        abort_unless($request->user()?->can(self::PERMISO), 403);

        return response()->json(['message' => 'Versión desactivada.', 'data' => $this->admin->detalle($this->admin->desactivar($master, $request->user()))]);
    }

    /**
     * "Probar con colaborador": PDF inline de vista previa (no se guarda).
     * Los faltantes viajan en el encabezado X-Faltantes (JSON).
     */
    public function probar(Request $request, DocumentTemplate $master): Response
    {
        abort_unless($request->user()?->can(self::PERMISO), 403);
        $datos = $request->validate(['colaborador_id' => ['required', 'integer', 'exists:colaboradores,id']]);
        $colaborador = Colaborador::query()->where('id', (int) $datos['colaborador_id'])->firstOrFail();
        $resultado = $this->admin->probar($master, $colaborador, $request->user());

        return response($resultado['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="vista-previa.pdf"',
            'X-Faltantes' => (string) json_encode(array_map(fn (array $f): string => (string) $f['etiqueta'], $resultado['faltantes']), JSON_UNESCAPED_UNICODE),
            'X-Fidelidad' => $resultado['fidelidad'],
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Original de Jurídico tal cual se entregó (auditoría).
     */
    public function original(Request $request, DocumentTemplate $master): Response
    {
        abort_unless($request->user()?->can(self::PERMISO), 403);
        $contenido = $this->almacen->original($master);
        $nombre = str_replace(['"', '\\', '/'], '', (string) ($master->original_nombre ?? 'original'));

        return response($contenido, 200, [
            'Content-Type' => str_ends_with(strtolower($nombre), '.pdf') ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="'.$nombre.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
