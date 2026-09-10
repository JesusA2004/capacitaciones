<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Formatos\FormatoCatalogoService;
use App\Services\Formatos\FormatoPreviewService;
use App\Services\Plantillas\PlantillaStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Catálogo de formatos y descarga de documentos ya generados desde la app
 * móvil de RH (mismos servicios que el panel web — ver
 * App\Http\Controllers\Rh\FormatoController — no se duplica la lógica de
 * generación). Generar un documento nuevo y la vista previa con variables
 * faltantes se quedan solo en el panel web por ahora: requieren un flujo de
 * selección/edición más largo del que tiene sentido en la app; aquí RH solo
 * consulta el catálogo y descarga lo ya generado.
 */
class FormatoController extends Controller
{
    public function __construct(
        private readonly FormatoCatalogoService $catalogo,
        private readonly PlantillaStorageService $storage,
        private readonly FormatoPreviewService $previsualizador,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', DocumentTemplate::class);

        return response()->json(['data' => $this->catalogo->listar()]);
    }

    public function descargar(Request $request, GeneratedDocument $documento): StreamedResponse
    {
        $this->autorizarDocumento($request, $documento);

        return $this->storage->respuesta($documento->path, [
            'Content-Disposition' => 'attachment; filename="'.$documento->generated_name.'"',
        ]);
    }

    public function descargarPdf(Request $request, GeneratedDocument $documento): HttpResponse|RedirectResponse
    {
        $this->autorizarDocumento($request, $documento);

        $pdf = $this->previsualizador->aPdf($this->storage->disco()->get($documento->path));
        abort_if($pdf === null, 422, 'No se pudo generar el PDF de este documento. Descarga el Word.');

        $nombre = pathinfo($documento->generated_name, PATHINFO_FILENAME).'.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$nombre.'"',
        ]);
    }

    private function autorizarDocumento(Request $request, GeneratedDocument $documento): void
    {
        $usuario = $request->user();
        abort_unless($usuario->can('formatos.descargar_docx') || $usuario->can('formatos.descargar_pdf'), 403);

        // Un documento generado para un colaborador nunca se sirve a quien
        // no puede ver a ese colaborador (mismo criterio de alcance que el
        // resto de la API RH movil). Los documentos de candidatos no tienen
        // alcance por sucursal que validar aqui (ya lo cubre el permiso
        // formatos.descargar_* en si).
        if ($documento->usuario !== null) {
            abort_unless($this->alcance->puedeVerUsuario($usuario, $documento->usuario), 404);
        }
    }
}
