<?php

namespace App\Http\Controllers;

use App\Enums\EstadoAltaDigital;
use App\Http\Requests\AltaPublica\GuardarConsentimientosRequest;
use App\Http\Requests\AltaPublica\GuardarDatosPersonalesRequest;
use App\Http\Requests\AltaPublica\SubirDocumentoRequest;
use App\Http\Requests\AltaPublica\SubirFotoRequest;
use App\Models\AltaDigital;
use App\Models\AltaDigitalDocumento;
use App\Models\DocumentType;
use App\Services\AltaDigital\AltaDigitalStorageService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AltaPublicaController extends Controller
{
    public function __construct(private readonly AltaDigitalStorageService $storage) {}

    public function show(AltaDigital $alta): Response
    {
        abort_unless($alta->tokenVigente(), 410, 'Esta liga ha expirado. Solicita una nueva a Recursos Humanos.');
        abort_unless(
            $alta->estado->permiteCaptura() || $alta->estado === EstadoAltaDigital::EnviadaPorCandidato,
            403,
            'Esta alta digital ya no acepta captura de datos.',
        );

        if ($alta->estado === EstadoAltaDigital::Enviada) {
            $alta->update(['estado' => EstadoAltaDigital::EnCaptura]);
        }

        $alta->load(['empresa:id,nombre', 'sucursal:id,nombre', 'departamento:id,nombre', 'puesto:id,nombre', 'documentos.tipo']);

        return Inertia::render('AltaPublica/Wizard', [
            'alta' => $alta,
            'documentosRequeridos' => DocumentType::query()
                ->where('aplica_alta', true)
                ->where('activo', true)
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'clave', 'requerido']),
            'soloLectura' => ! $alta->estado->permiteCaptura(),
        ]);
    }

    public function guardarDatosPersonales(GuardarDatosPersonalesRequest $request, AltaDigital $alta): RedirectResponse
    {
        $alta->update($request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Datos personales guardados.']);
    }

    public function subirFoto(SubirFotoRequest $request, AltaDigital $alta): RedirectResponse
    {
        if ($alta->foto_path) {
            $this->storage->eliminar($alta->foto_path);
        }

        $archivo = $request->file('foto');
        $nombreInterno = $this->storage->nombreInterno($archivo->getClientOriginalName());
        $ruta = $this->storage->ruta($alta->id, 'foto', $nombreInterno);
        $this->storage->guardar($archivo, $ruta);

        $alta->update([
            'foto_disk' => config('altas.disk'),
            'foto_path' => $ruta,
            'foto_original_name' => $archivo->getClientOriginalName(),
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Fotografía cargada.']);
    }

    public function subirDocumento(SubirDocumentoRequest $request, AltaDigital $alta): RedirectResponse
    {
        $archivo = $request->file('archivo');
        $nombreInterno = $this->storage->nombreInterno($archivo->getClientOriginalName());
        $ruta = $this->storage->ruta($alta->id, 'documentos', $nombreInterno);
        $this->storage->guardar($archivo, $ruta);

        $documentoExistente = $alta->documentos()->where('document_type_id', $request->validated('document_type_id'))->first();

        if ($documentoExistente) {
            $this->storage->eliminar($documentoExistente->path);
            $documentoExistente->delete();
        }

        AltaDigitalDocumento::create([
            'alta_digital_id' => $alta->id,
            'document_type_id' => $request->validated('document_type_id'),
            'disk' => config('altas.disk'),
            'path' => $ruta,
            'original_name' => $archivo->getClientOriginalName(),
            'mime' => $archivo->getClientMimeType(),
            'size' => $archivo->getSize(),
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Documento cargado.']);
    }

    public function guardarConsentimientos(GuardarConsentimientosRequest $request, AltaDigital $alta): RedirectResponse
    {
        $rutaFirma = $this->guardarFirmaBase64($alta, $request->validated('firma'));

        $alta->update([
            'aviso_privacidad_aceptado' => true,
            'aviso_privacidad_aceptado_en' => now(),
            'consentimiento_datos_aceptado' => true,
            'consentimiento_datos_aceptado_en' => now(),
            'firma_disk' => config('altas.disk'),
            'firma_path' => $rutaFirma,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Aviso y consentimiento registrados.']);
    }

    public function enviar(AltaDigital $alta): RedirectResponse
    {
        abort_unless($alta->tokenVigente() && $alta->estado->permiteCaptura(), 403);
        abort_unless($alta->aviso_privacidad_aceptado && $alta->consentimiento_datos_aceptado, 422, 'Debes aceptar el aviso de privacidad y el consentimiento antes de enviar.');

        $alta->update([
            'estado' => EstadoAltaDigital::EnviadaPorCandidato,
            'enviada_en' => now(),
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Tu información fue enviada a Recursos Humanos.']);
    }

    private function guardarFirmaBase64(AltaDigital $alta, string $dataUrl): string
    {
        if ($alta->firma_path) {
            $this->storage->eliminar($alta->firma_path);
        }

        [, $datosCodificados] = array_pad(explode(',', $dataUrl, 2), 2, '');
        $binario = base64_decode($datosCodificados, true) ?: '';

        $ruta = $this->storage->ruta($alta->id, 'firma', $this->storage->nombreInterno('firma.png'));
        $this->storage->disco()->put($ruta, $binario);

        return $ruta;
    }
}
