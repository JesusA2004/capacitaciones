<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Models\OfficialFormatGeneration;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Formatos\OfficialFormatStorageService;
use App\Services\Solicitudes\SolicitudFormatoOficialService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Formatos oficiales YA generados (motor anterior, retirado de la interfaz):
 * solo descargar/previsualizar lo que el expediente enlaza y archivar su
 * firmado. Crear, versionar o generar formatos se retiró: los formatos viven
 * en Documentos maestros y se generan en Documentos del proceso.
 */
class FormatoOficialController extends Controller
{
    public function __construct(
        private readonly OfficialFormatStorageService $storage,
        private readonly DocumentoStorageService $expediente,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly SolicitudFormatoOficialService $formatoDeSolicitud,
    ) {}

    public function descargar(Request $request, OfficialFormatGeneration $generacion): StreamedResponse
    {
        $this->autorizarGeneracion($request, $generacion);

        return $this->respuestaGeneracion($generacion, 'attachment');
    }

    /**
     * Igual que descargar(), pero inline (visor embebido).
     */
    public function previsualizar(Request $request, OfficialFormatGeneration $generacion): StreamedResponse
    {
        $this->autorizarGeneracion($request, $generacion);

        return $this->respuestaGeneracion($generacion, 'inline');
    }

    public function subirFirmado(Request $request, OfficialFormatGeneration $generacion): RedirectResponse
    {
        $this->autorizarGeneracion($request, $generacion);
        abort_unless($request->user()->can('formatos_oficiales.generar'), 403);

        $request->validate([
            'archivo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:20480'],
        ]);

        $this->formatoDeSolicitud->archivarFirmado($generacion, $request->file('archivo'), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Documento firmado archivado.']);
    }

    private function respuestaGeneracion(OfficialFormatGeneration $generacion, string $disposicion): StreamedResponse
    {
        $nombre = str_replace(['"', '\\', '/'], '', $generacion->generated_name);
        $headers = ['Content-Type' => 'application/pdf', 'Content-Disposition' => sprintf('%s; filename="%s"', $disposicion, $nombre)];

        if ($generacion->generated_disk === config('formatos_oficiales.disk')) {
            return $this->storage->respuesta($generacion->generated_path, $headers);
        }

        abort_unless(Storage::disk($generacion->generated_disk)->exists($generacion->generated_path), 404, 'El archivo no está disponible.');

        return $this->expediente->respuesta($generacion->generated_path, $headers);
    }

    private function autorizarGeneracion(Request $request, OfficialFormatGeneration $generacion): void
    {
        $usuario = $request->user();
        abort_unless($usuario->can('formatos_oficiales.descargar'), 403);

        if ($generacion->colaborador !== null) {
            abort_unless($this->alcance->puedeVerExpediente($usuario, $generacion->colaborador), 404);
        }
    }
}
