<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\AplicarExtraccionRequest;
use App\Models\Colaborador;
use App\Models\EmployeeDocument;
use App\Models\ExpedienteHistorico;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Documentos\DocumentExtractionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Revisión RH de las sugerencias de datos detectados en un documento de
 * expediente o en un PDF histórico (docs/DOCUMENT_EXTRACTION.md). Nunca
 * modifica al colaborador salvo en aplicar()/aplicarHistorico(), y solo con
 * los campos que RH decide explícitamente.
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
        abort_unless($documento->colaborador !== null && $usuario->can('rh.documentos.extraccion.ver') && $this->alcance->puedeVerExpediente($usuario, $documento->colaborador), 403);

        return response()->json([
            'elegible' => DocumentExtractionService::tipoElegible($documento->tipo->clave),
            'extraccion' => $documento->extraccion,
        ]);
    }

    public function aplicar(AplicarExtraccionRequest $request, EmployeeDocument $documento): RedirectResponse
    {
        abort_unless($documento->colaborador !== null && $this->alcance->puedeVerExpediente($request->user(), $documento->colaborador), 403);

        $extraccion = $documento->extraccion ?? abort(404, 'Este documento no tiene una extracción registrada.');

        $this->extraccion->aplicar($extraccion, $request->validated('valores'), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Datos aplicados al colaborador.']);
    }

    public function ignorar(Request $request, EmployeeDocument $documento): RedirectResponse
    {
        $usuario = $request->user();
        abort_unless($documento->colaborador !== null && $usuario->can('rh.documentos.extraccion.ignorar') && $this->alcance->puedeVerExpediente($usuario, $documento->colaborador), 403);

        $extraccion = $documento->extraccion ?? abort(404, 'Este documento no tiene una extracción registrada.');

        $this->extraccion->ignorar($extraccion, $usuario);

        return back()->with('toast', ['type' => 'success', 'message' => 'Sugerencias descartadas.']);
    }

    public function reprocesar(Request $request, EmployeeDocument $documento): RedirectResponse
    {
        $usuario = $request->user();
        abort_unless($documento->colaborador !== null && $usuario->can('rh.documentos.extraccion.reprocesar') && $this->alcance->puedeVerExpediente($usuario, $documento->colaborador), 403);

        $this->extraccion->reprocesar($documento);

        return back()->with('toast', ['type' => 'success', 'message' => 'Documento enviado a re-procesar.']);
    }

    public function showHistorico(Request $request, ExpedienteHistorico $historico): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($historico->colaborador !== null && $usuario->can('rh.documentos.extraccion.ver') && $this->alcance->puedeVerExpediente($usuario, $historico->colaborador), 403);

        return response()->json([
            'elegible' => DocumentExtractionService::historicoElegible($historico),
            'extraccion' => $historico->extraccion,
        ]);
    }

    public function aplicarHistorico(AplicarExtraccionRequest $request, ExpedienteHistorico $historico): RedirectResponse
    {
        abort_unless($historico->colaborador !== null && $this->alcance->puedeVerExpediente($request->user(), $historico->colaborador), 403);

        $extraccion = $historico->extraccion ?? abort(404, 'Este expediente histórico no tiene una extracción registrada.');

        $this->extraccion->aplicar($extraccion, $request->validated('valores'), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Datos aplicados al colaborador.']);
    }

    public function ignorarHistorico(Request $request, ExpedienteHistorico $historico): RedirectResponse
    {
        $usuario = $request->user();
        abort_unless($historico->colaborador !== null && $usuario->can('rh.documentos.extraccion.ignorar') && $this->alcance->puedeVerExpediente($usuario, $historico->colaborador), 403);

        $extraccion = $historico->extraccion ?? abort(404, 'Este expediente histórico no tiene una extracción registrada.');

        $this->extraccion->ignorar($extraccion, $usuario);

        return back()->with('toast', ['type' => 'success', 'message' => 'Sugerencias descartadas.']);
    }

    public function reprocesarHistorico(Request $request, ExpedienteHistorico $historico): RedirectResponse
    {
        $usuario = $request->user();
        abort_unless($historico->colaborador !== null && $usuario->can('rh.documentos.extraccion.reprocesar') && $this->alcance->puedeVerExpediente($usuario, $historico->colaborador), 403);
        abort_unless(DocumentExtractionService::historicoElegible($historico), 422, 'Este expediente histórico no está vinculado a un colaborador.');

        $this->extraccion->reprocesarHistorico($historico);

        return back()->with('toast', ['type' => 'success', 'message' => 'Expediente histórico enviado a re-procesar.']);
    }

    /**
     * Resumen de sugerencias pendientes de revisión (de documentos normales
     * y del histórico) para el panel del expediente (encargo §17) — nunca
     * reemplaza ExtraccionDocumentoDialog, solo ayuda a encontrarlas.
     */
    public function resumen(Request $request, Colaborador $colaborador): JsonResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('rh.documentos.extraccion.ver') && $this->alcance->puedeVerExpediente($usuario, $colaborador), 403);

        $pendientes = $this->extraccion->pendientes($colaborador)->map(fn ($extraccion) => [
            'id' => $extraccion->id,
            'fuente_tipo' => $extraccion->fuenteTipo(),
            'fuente_nombre' => $extraccion->fuenteNombre(),
            'employee_document_id' => $extraccion->employee_document_id,
            'expediente_historico_id' => $extraccion->expediente_historico_id,
            'campos' => collect($extraccion->differences ?? [])->filter(fn (array $d) => ! ($d['coincide'] ?? false))->keys()->all(),
        ])->values();

        return response()->json([
            'pendientes' => $pendientes,
            'conflictos' => $this->extraccion->conflictos($colaborador),
        ]);
    }
}
