<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\AplicarExtraccionRequest;
use App\Models\EmployeeDocument;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Documentos\DocumentExtractionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Revisión RH de las sugerencias de datos detectados en un documento de
 * expediente (docs/DOCUMENT_EXTRACTION.md). Nunca modifica al colaborador
 * salvo en aplicar(), y solo con los campos que RH decide explícitamente.
 */
class DocumentExtraccionController extends Controller
{
    public function __construct(
        private readonly DocumentExtractionService $extraccion,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    public function show(Request $request, EmployeeDocument $documento): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.documentos.extraccion.ver') && $this->alcance->puedeVerExpediente($usuario, $documento->usuario), 403);

        return response()->json([
            'elegible' => DocumentExtractionService::tipoElegible($documento->tipo->clave),
            'extraccion' => $documento->extraccion,
        ]);
    }

    public function aplicar(AplicarExtraccionRequest $request, EmployeeDocument $documento): RedirectResponse
    {
        abort_unless($this->alcance->puedeVerExpediente($request->user(), $documento->usuario), 403);

        $extraccion = $documento->extraccion ?? abort(404, 'Este documento no tiene una extracción registrada.');

        $this->extraccion->aplicar($extraccion, $request->validated('valores'), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Datos aplicados al colaborador.']);
    }

    public function ignorar(Request $request, EmployeeDocument $documento): RedirectResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.documentos.extraccion.ignorar') && $this->alcance->puedeVerExpediente($usuario, $documento->usuario), 403);

        $extraccion = $documento->extraccion ?? abort(404, 'Este documento no tiene una extracción registrada.');

        $this->extraccion->ignorar($extraccion, $usuario);

        return back()->with('toast', ['type' => 'success', 'message' => 'Sugerencias descartadas.']);
    }

    public function reprocesar(Request $request, EmployeeDocument $documento): RedirectResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.documentos.extraccion.reprocesar') && $this->alcance->puedeVerExpediente($usuario, $documento->usuario), 403);

        $this->extraccion->reprocesar($documento);

        return back()->with('toast', ['type' => 'success', 'message' => 'Documento enviado a re-procesar.']);
    }
}
