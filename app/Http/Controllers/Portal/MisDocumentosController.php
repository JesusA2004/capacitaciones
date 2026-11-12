<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SubirDocumentoIncorporacionRequest;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Services\Incorporacion\IncorporacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Documentos del PROPIO expediente desde la web («Mi expediente»): lo mismo
 * que la app hace con colaborador/incorporacion/documentos/* — mismas
 * reglas (IncorporacionService): guarda de estado (nunca pisa un documento
 * en revisión o aprobado), aviso + tarea de revisión a RH, pares y reverso.
 * Siempre la cuenta en sesión; nunca recibe el id de otra persona.
 *
 * `portal.documentos.subir` es reutilizable (asistente de registro web):
 * responde JSON a quien lo pide por fetch y `back()` a Inertia.
 */
class MisDocumentosController extends Controller
{
    public function __construct(private readonly IncorporacionService $incorporacion) {}

    public function subir(SubirDocumentoIncorporacionRequest $request, DocumentType $documentType): RedirectResponse|JsonResponse
    {
        $colaborador = $this->colaboradorDe($request);

        try {
            $this->incorporacion->subirDocumento(
                $colaborador,
                $documentType,
                $request->file('archivo'),
                $request->user()->id,
                $request->boolean('incluye_par'),
                $request->file('archivo_reverso'),
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['archivo' => $e->getMessage()]);
        }

        if ($this->quiereJson($request)) {
            return response()->json($this->incorporacion->estadoIncorporacion($colaborador->refresh()));
        }

        return back()->with('toast', ['type' => 'success', 'message' => sprintf('«%s» quedó en revisión de Recursos Humanos.', $documentType->nombre)]);
    }

    public function solicitarCambio(Request $request, DocumentType $documentType): RedirectResponse|JsonResponse
    {
        try {
            $this->incorporacion->solicitarCambio($this->colaboradorDe($request), $documentType);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['documento' => $e->getMessage()]);
        }

        if ($this->quiereJson($request)) {
            return response()->json(['message' => 'Solicitud de cambio enviada a RH']);
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'Solicitud de cambio enviada. Cuando RH la autorice podrás subir la nueva versión.']);
    }

    private function quiereJson(Request $request): bool
    {
        return $request->expectsJson() && ! $request->hasHeader('X-Inertia');
    }

    private function colaboradorDe(Request $request): Colaborador
    {
        $colaborador = $request->user()?->colaborador;
        abort_if($colaborador === null, 403, 'Tu cuenta no tiene un colaborador enlazado.');

        return $colaborador;
    }
}
