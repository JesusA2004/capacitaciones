<?php

namespace App\Http\Controllers\Capacitacion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Capacitacion\SubirCertificacionFirmadaRequest;
use App\Models\CertificacionMrLana;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\Certificados\CertificacionFormatoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Certificación Mr. Lana por formato oficial RH-CC-R-001 (web): descargar
 * el formato con el encabezado lleno, subir el firmado y consultar el
 * firmado y el certificado. Delgado: todo vive en CertificacionFormatoService;
 * la autorización, en CertificacionMrLanaPolicy.
 */
class CertificacionFormatoController extends Controller
{
    public function __construct(private readonly CertificacionFormatoService $certificacion) {}

    public function formato(Request $request, Colaborador $colaborador): Response
    {
        $this->authorize('gestionar', [CertificacionMrLana::class, $colaborador]);

        $resultado = $this->certificacion->descargarFormato($colaborador, $this->usuario($request));

        return response($resultado['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $resultado['nombre']),
            'Cache-Control' => 'no-store',
        ]);
    }

    public function subirFirmado(SubirCertificacionFirmadaRequest $request, Colaborador $colaborador): RedirectResponse
    {
        $this->authorize('gestionar', [CertificacionMrLana::class, $colaborador]);

        $archivo = $request->file('archivo');
        abort_unless($archivo instanceof UploadedFile, 422);

        $this->certificacion->subirFirmado($colaborador, $archivo, (float) $request->input('promedio'), $this->usuario($request));

        return back()->with('toast', ['type' => 'success', 'message' => sprintf('%s ya cuenta con su Certificación Mr. Lana.', $colaborador->nombreCompleto())]);
    }

    public function firmado(Colaborador $colaborador): StreamedResponse
    {
        $this->authorize('gestionar', [CertificacionMrLana::class, $colaborador]);

        return $this->certificacion->respuestaFirmado($colaborador);
    }

    public function certificado(Request $request, Colaborador $colaborador): Response
    {
        $this->authorize('gestionar', [CertificacionMrLana::class, $colaborador]);

        return $this->certificacion->respuestaDiploma($colaborador, $request->boolean('descargar'));
    }

    private function usuario(Request $request): User
    {
        $usuario = $request->user();
        abort_unless($usuario instanceof User, 401);

        return $usuario;
    }
}
