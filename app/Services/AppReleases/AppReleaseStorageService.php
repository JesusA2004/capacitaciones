<?php

namespace App\Services\AppReleases;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unica puerta de entrada al almacenamiento de APKs (disco 'nas', config
 * ('mobile_releases.disk')). Espejo deliberado de
 * App\Services\Expedientes\DocumentoStorageService / CvStorageService:
 * ningun controlador debe llamar Storage::disk() directamente ni exponer la
 * ruta fisica real (file_path) al frontend.
 */
class AppReleaseStorageService
{
    public function disco(): Filesystem
    {
        return Storage::disk(config('mobile_releases.disk'));
    }

    public function rutaApk(string $platform, string $nombreInterno): string
    {
        return "app-releases/{$platform}/{$nombreInterno}";
    }

    public function nombreInterno(string $nombreOriginal): string
    {
        $extension = pathinfo($nombreOriginal, PATHINFO_EXTENSION);
        $uuid = (string) Str::uuid();

        return $extension !== '' ? "{$uuid}.{$extension}" : $uuid;
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

    public function hashSha256(string $ruta): string
    {
        $flujo = $this->disco()->readStream($ruta);
        $contexto = hash_init('sha256');

        while (! feof($flujo)) {
            $bloque = fread($flujo, 1024 * 1024);

            if ($bloque !== false) {
                hash_update($contexto, $bloque);
            }
        }
        fclose($flujo);

        return hash_final($contexto);
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
