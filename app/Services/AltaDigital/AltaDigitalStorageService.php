<?php

namespace App\Services\AltaDigital;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unica puerta de entrada al almacenamiento de archivos de alta digital
 * (foto, firma simple, documentos) en el disco 'nas'
 * (config('altas.disk')). Espejo deliberado de
 * App\Services\Expedientes\DocumentoStorageService y
 * App\Services\Reclutamiento\CvStorageService.
 */
class AltaDigitalStorageService
{
    public function disco(): Filesystem
    {
        return Storage::disk(config('altas.disk'));
    }

    public function nombreInterno(string $nombreOriginal): string
    {
        $extension = pathinfo($nombreOriginal, PATHINFO_EXTENSION);
        $uuid = (string) Str::uuid();

        return $extension !== '' ? "{$uuid}.{$extension}" : $uuid;
    }

    public function ruta(int $altaDigitalId, string $subcarpeta, string $nombreInterno): string
    {
        return "altas/{$altaDigitalId}/{$subcarpeta}/{$nombreInterno}";
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
