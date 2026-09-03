<?php

namespace App\Services\Solicitudes;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unica puerta de entrada al almacenamiento de adjuntos de solicitudes
 * internas (disco 'nas', config('expedientes.disk') — se reutiliza el mismo
 * disco que expedientes, no uno nuevo). Espejo deliberado de
 * App\Services\Expedientes\DocumentoStorageService y
 * App\Services\Plantillas\PlantillaStorageService.
 */
class SolicitudDocumentoStorageService
{
    public function disco(): Filesystem
    {
        return Storage::disk(config('expedientes.disk'));
    }

    public function nombreInterno(string $nombreOriginal): string
    {
        $extension = pathinfo($nombreOriginal, PATHINFO_EXTENSION);
        $uuid = (string) Str::uuid();

        return $extension !== '' ? "{$uuid}.{$extension}" : $uuid;
    }

    public function rutaDocumento(int $solicitudId, string $nombreInterno): string
    {
        return "solicitudes/{$solicitudId}/{$nombreInterno}";
    }

    public function guardar(UploadedFile $archivo, string $rutaDestino): string
    {
        $carpeta = dirname($rutaDestino);
        $nombre = basename($rutaDestino);

        $this->disco()->putFileAs($carpeta, $archivo, $nombre);

        return $rutaDestino;
    }

    public function eliminar(string $ruta): void
    {
        if ($this->disco()->exists($ruta)) {
            $this->disco()->delete($ruta);
        }
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function respuesta(string $ruta, array $headers = []): StreamedResponse
    {
        /** @var FilesystemAdapter $adaptador */
        $adaptador = $this->disco();

        return $adaptador->response($ruta, null, $headers);
    }
}
