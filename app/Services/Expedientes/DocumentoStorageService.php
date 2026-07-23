<?php

namespace App\Services\Expedientes;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unica puerta de entrada al almacenamiento de documentos de expediente
 * (disco 'nas', config('expedientes.disk')). Ningun controlador debe llamar
 * Storage::disk() directamente para estos archivos; espejo deliberado de
 * App\Services\Multimedia\MediaStorageService para el mismo disco NAS, pero
 * con las rutas logicas propias de documentos laborales en vez de video.
 */
class DocumentoStorageService
{
    public function disco(): Filesystem
    {
        return Storage::disk(config('expedientes.disk'));
    }

    /**
     * Nombre de archivo interno no predecible (UUID): nunca se guarda ni se
     * expone el nombre original del archivo como nombre real en disco.
     */
    public function nombreInterno(string $nombreOriginal): string
    {
        $extension = pathinfo($nombreOriginal, PATHINFO_EXTENSION);
        $uuid = (string) Str::uuid();

        return $extension !== '' ? "{$uuid}.{$extension}" : $uuid;
    }

    public function rutaDocumento(int $usuarioId, string $nombreInterno): string
    {
        return "expedientes/{$usuarioId}/{$nombreInterno}";
    }

    public function guardar(UploadedFile $archivo, string $rutaDestino): string
    {
        $carpeta = dirname($rutaDestino);
        $nombre = basename($rutaDestino);

        $this->disco()->putFileAs($carpeta, $archivo, $nombre);

        return $rutaDestino;
    }

    public function existe(string $ruta): bool
    {
        return $this->disco()->exists($ruta);
    }

    public function eliminar(string $ruta): void
    {
        if ($this->existe($ruta)) {
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
     * Respuesta HTTP en streaming (visor/descarga). El controlador que la
     * invoca ya valido el permiso antes de llegar aqui; esta capa solo sirve
     * bytes desde la ruta logica, nunca expone la ruta fisica al cliente.
     *
     * @param  array<string, string>  $headers
     */
    public function respuesta(string $ruta, array $headers = []): StreamedResponse
    {
        /** @var FilesystemAdapter $adaptador */
        $adaptador = $this->disco();

        return $adaptador->response($ruta, null, $headers);
    }
}
