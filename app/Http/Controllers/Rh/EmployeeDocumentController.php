<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EstadoDocumento;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\SubirDocumentoRequest;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Services\Expedientes\CartaCompromisoService;
use App\Services\Expedientes\DocumentoParService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Incorporacion\IncorporacionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeDocumentController extends Controller
{
    public function __construct(
        private readonly DocumentoStorageService $storage,
        private readonly DocumentoParService $pares,
        private readonly IncorporacionService $incorporacion,
        private readonly CartaCompromisoService $cartas,
    ) {}

    /**
     * Sube un documento al expediente de un colaborador. Si ya existe una
     * version vigente del mismo tipo, la archiva y enlaza la nueva como su
     * sucesora (previous_version_id), en vez de sobrescribirla: el
     * historial de versiones queda completo en la tabla.
     */
    public function store(SubirDocumentoRequest $request, Colaborador $colaborador): RedirectResponse
    {
        $tipo = DocumentType::findOrFail($request->integer('document_type_id'));
        $versionAnterior = EmployeeDocument::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('document_type_id', $tipo->id)
            ->where('status', '!=', EstadoDocumento::Archivado->value)
            ->exists();

        try {
            ['documento' => $documento, 'par' => $par] = $this->pares->subir($colaborador, $tipo, $request->file('archivo'), $request->user()->id, $request->boolean('incluye_par'));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['archivo' => $e->getMessage()]);
        }

        $this->cartas->sincronizarSinFallar($colaborador);

        $mensaje = $versionAnterior
            ? "Nueva versión de «{$tipo->nombre}» cargada (v{$documento->version}). Queda en revisión."
            : "«{$tipo->nombre}» cargado correctamente. Queda en revisión.";

        return back()->with('toast', [
            'type' => 'success',
            'message' => $par !== null
                ? sprintf('«%s» y «%s» cargadas con el mismo archivo. Revisa y aprueba cada una por separado.', $tipo->nombre, $par->tipo->nombre)
                : $mensaje,
        ]);
    }

    public function descargar(Request $request, EmployeeDocument $documento): StreamedResponse
    {
        $this->authorize('descargar', $documento);

        return $this->storage->respuesta($documento->path, [
            'Content-Type' => $documento->mime ?? 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.$documento->original_name.'"',
        ]);
    }

    /**
     * Aprobar/rechazar/pedir corrección: la regla (estado + aviso al
     * colaborador + aviso a RH si la incorporación quedó completa) vive en
     * IncorporacionService, la misma que usa la API móvil.
     */
    public function aprobar(Request $request, EmployeeDocument $documento): RedirectResponse
    {
        $this->authorize('aprobar', $documento);

        $datos = $request->validate(['comments' => ['nullable', 'string', 'max:500']]);

        $this->incorporacion->aprobarDocumento($documento, $request->user(), ($datos['comments'] ?? '') !== '' ? $datos['comments'] : null);

        return back()->with('toast', ['type' => 'success', 'message' => 'Documento aprobado.']);
    }

    public function rechazar(Request $request, EmployeeDocument $documento): RedirectResponse
    {
        $this->authorize('rechazar', $documento);

        $datos = $request->validate(['rejection_reason' => ['required', 'string', 'max:500']]);

        $this->incorporacion->rechazarDocumento($documento, $request->user(), $datos['rejection_reason']);

        return back()->with('toast', ['type' => 'success', 'message' => 'Documento rechazado.']);
    }

    public function solicitarCorreccion(Request $request, EmployeeDocument $documento): RedirectResponse
    {
        $this->authorize('pedirCorreccion', $documento);

        $datos = $request->validate(['comments' => ['required', 'string', 'max:500']]);

        $this->incorporacion->solicitarCorreccionDocumento($documento, $request->user(), $datos['comments']);

        return back()->with('toast', ['type' => 'success', 'message' => 'Se pidió corrección al colaborador.']);
    }

    /**
     * El colaborador pidió reemplazar un documento (cambio_solicitado): RH
     * autoriza el cambio y el colaborador ya puede subir la nueva versión.
     * Misma regla que la API móvil (IncorporacionService::autorizarCambio).
     */
    public function autorizarCambio(Request $request, EmployeeDocument $documento): RedirectResponse
    {
        $this->authorize('autorizarCambio', $documento);

        try {
            $this->incorporacion->autorizarCambio($documento, $request->user());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['documento' => $e->getMessage()]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'Cambio autorizado. El colaborador ya puede subir la nueva versión.']);
    }
}
