<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\CicloLaboral\ArchivoLaboralRequest;
use App\Http\Requests\CicloLaboral\OperacionDocumentoRequest;
use App\Models\GeneratedDocument;
use App\Services\DocumentosLaborales\FlujoDocumentalService;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Control del original físico de un documento laboral desde la web
 * (impresión → firma/huella → envío a corporativo → recepción → escaneo →
 * archivo). Mismo FlujoDocumentalService y misma GeneratedDocumentPolicy que
 * Api\V1\Rh\DocumentoLaboralController.
 */
class DocumentoLaboralController extends Controller
{
    public function __construct(
        private readonly MotorDocumentalService $motor,
        private readonly FlujoDocumentalService $flujo,
    ) {}

    public function descargar(GeneratedDocument $documento): StreamedResponse
    {
        $this->authorize('descargar', $documento);

        return $this->motor->respuesta($documento);
    }

    public function imprimir(OperacionDocumentoRequest $request, GeneratedDocument $documento): RedirectResponse
    {
        $this->authorize('operar', $documento);
        $this->flujo->marcarImpreso($documento, $request->user(), $request->validated('observaciones'));

        return $this->listo('Documento marcado como impreso.');
    }

    public function firmaFisica(OperacionDocumentoRequest $request, GeneratedDocument $documento): RedirectResponse
    {
        $this->authorize('operar', $documento);
        $this->flujo->registrarFirmaFisica($documento, $request->user(), $request->safe()->only(['huella_registrada', 'testigos', 'observaciones', 'fecha']));

        return $this->listo('Firma física registrada.');
    }

    public function envio(OperacionDocumentoRequest $request, GeneratedDocument $documento): RedirectResponse
    {
        $this->authorize('operar', $documento);
        $request->validate([
            'paqueteria' => ['required', 'string', 'max:80'],
            'numero_guia' => ['required', 'string', 'max:80'],
        ]);
        $comprobante = $request->file('comprobante');

        $this->flujo->registrarEnvio(
            $documento,
            $request->user(),
            [
                'paqueteria' => (string) $request->validated('paqueteria'),
                'numero_guia' => (string) $request->validated('numero_guia'),
                'observaciones' => $request->validated('observaciones'),
                'fecha' => $request->validated('fecha'),
            ],
            $comprobante instanceof UploadedFile ? $comprobante : null,
        );

        return $this->listo('Envío a corporativo registrado.');
    }

    public function recepcion(OperacionDocumentoRequest $request, GeneratedDocument $documento): RedirectResponse
    {
        $this->authorize('operar', $documento);
        $this->flujo->registrarRecepcion($documento, $request->user(), $request->validated('observaciones'), $request->validated('fecha'));

        return $this->listo('Recepción en corporativo registrada.');
    }

    public function escaneo(ArchivoLaboralRequest $request, GeneratedDocument $documento): RedirectResponse
    {
        $this->authorize('operar', $documento);
        $archivo = $request->file('archivo');
        abort_unless($archivo instanceof UploadedFile, 422);

        $this->flujo->registrarEscaneo($documento, $request->user(), $archivo, $request->validated('observaciones'));

        return $this->listo('Escaneo del original guardado en el expediente.');
    }

    public function archivar(OperacionDocumentoRequest $request, GeneratedDocument $documento): RedirectResponse
    {
        $this->authorize('operar', $documento);
        $this->flujo->archivar($documento, $request->user(), $request->validated('observaciones'));

        return $this->listo('Original archivado.');
    }

    private function listo(string $mensaje): RedirectResponse
    {
        return back()->with('toast', ['type' => 'success', 'message' => $mensaje]);
    }
}
