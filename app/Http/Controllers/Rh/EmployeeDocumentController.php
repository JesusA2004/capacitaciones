<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EstadoDocumento;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\SubirDocumentoRequest;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\Expedientes\DocumentoStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeDocumentController extends Controller
{
    public function __construct(private readonly DocumentoStorageService $storage) {}

    /**
     * Sube un documento al expediente de un colaborador. Si ya existe una
     * version vigente del mismo tipo, la archiva y enlaza la nueva como su
     * sucesora (previous_version_id), en vez de sobrescribirla: el
     * historial de versiones queda completo en la tabla.
     */
    public function store(SubirDocumentoRequest $request, User $colaborador): RedirectResponse
    {
        $tipo = DocumentType::findOrFail($request->integer('document_type_id'));
        $versionAnterior = EmployeeDocument::query()
            ->where('user_id', $colaborador->id)
            ->where('document_type_id', $tipo->id)
            ->where('status', '!=', EstadoDocumento::Archivado->value)
            ->exists();

        $documento = $this->storage->subirVersion($colaborador, $tipo, $request->file('archivo'), $request->user()->id);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $versionAnterior
                ? "Nueva versión de «{$tipo->nombre}» cargada (v{$documento->version}). Queda en revisión."
                : "«{$tipo->nombre}» cargado correctamente. Queda en revisión.",
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

    public function aprobar(Request $request, EmployeeDocument $documento): RedirectResponse
    {
        $this->authorize('aprobar', $documento);

        $documento->update([
            'status' => EstadoDocumento::Aprobado->value,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'comments' => $request->string('comments')->toString() ?: null,
            'rejection_reason' => null,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Documento aprobado.']);
    }

    public function rechazar(Request $request, EmployeeDocument $documento): RedirectResponse
    {
        $this->authorize('rechazar', $documento);

        $request->validate(['rejection_reason' => ['required', 'string', 'max:500']]);

        $documento->update([
            'status' => EstadoDocumento::Rechazado->value,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'rejection_reason' => $request->string('rejection_reason')->toString(),
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Documento rechazado.']);
    }

    public function solicitarCorreccion(Request $request, EmployeeDocument $documento): RedirectResponse
    {
        $this->authorize('pedirCorreccion', $documento);

        $request->validate(['comments' => ['required', 'string', 'max:500']]);

        $documento->update([
            'status' => EstadoDocumento::RequiereCorreccion->value,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'comments' => $request->string('comments')->toString(),
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Se pidió corrección al colaborador.']);
    }
}
