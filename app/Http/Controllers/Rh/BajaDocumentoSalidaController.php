<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bajas\AdjuntarDocumentoBajaRequest;
use App\Http\Requests\Bajas\GuardarAvisoRescisionRequest;
use App\Models\Baja;
use App\Services\Bajas\BajaDocumentoSalidaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «Generar documento de salida» del expediente de la baja. Delgado: autoriza
 * con BajaPolicy::documentoSalida y delega en BajaDocumentoSalidaService.
 */
class BajaDocumentoSalidaController extends Controller
{
    public function __construct(private readonly BajaDocumentoSalidaService $salida) {}

    public function guardarAviso(GuardarAvisoRescisionRequest $request, Baja $baja): RedirectResponse
    {
        $this->authorize('documentoSalida', $baja);
        $this->salida->guardarAviso($baja, $request->validated(), $request->user());

        return $this->ok('Aviso de rescisión guardado.');
    }

    public function validarPlazo(Request $request, Baja $baja): RedirectResponse
    {
        $this->authorize('validarPlazoAviso', $baja);
        $this->salida->validarPlazo($baja, $request->user());

        return $this->ok('Plazo validado; quedó en la bitácora.');
    }

    public function vistaPrevia(Request $request, Baja $baja): Response
    {
        $this->authorize('documentoSalida', $baja);

        return response($this->salida->vistaPrevia($baja, $request->user()), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Vista previa - Documento de salida.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function generar(Request $request, Baja $baja): RedirectResponse
    {
        $this->authorize('documentoSalida', $baja);
        $datos = $request->validate(['motivo' => ['nullable', 'string', 'max:500']]);
        $this->salida->generar($baja, $request->user(), $datos['motivo'] ?? null);

        return $this->ok('Documento generado: descárgalo para firma.');
    }

    public function descargar(Baja $baja): StreamedResponse
    {
        $this->authorize('documentoSalida', $baja);

        return $this->salida->descargar($baja);
    }

    public function subirFirmado(AdjuntarDocumentoBajaRequest $request, Baja $baja): RedirectResponse
    {
        $this->authorize('documentoSalida', $baja);
        $archivo = $request->file('archivo');
        abort_unless($archivo instanceof UploadedFile, 422, 'Elige un archivo.');
        $this->salida->subirFirmado($baja, $archivo, $request->user());

        return $this->ok('Documento firmado adjunto al expediente.');
    }

    public function subirEvidencia(AdjuntarDocumentoBajaRequest $request, Baja $baja): RedirectResponse
    {
        $this->authorize('documentoSalida', $baja);
        $archivo = $request->file('archivo');
        abort_unless($archivo instanceof UploadedFile, 422, 'Elige un archivo.');
        $this->salida->subirEvidencia($baja, $archivo, $request->user());

        return $this->ok('Evidencia adjunta al expediente.');
    }

    private function ok(string $mensaje): RedirectResponse
    {
        return back()->with('toast', ['type' => 'success', 'message' => $mensaje]);
    }
}
