<?php

namespace App\Services\Plantillas;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unica puerta de entrada al almacenamiento de plantillas oficiales y
 * documentos generados (disco 'nas', config('plantillas.disk')). Espejo
 * deliberado de los demas *StorageService del proyecto.
 */
class PlantillaStorageService
{
    public function disco(): Filesystem
    {
        return Storage::disk(config('plantillas.disk'));
    }

    public function nombreInterno(string $nombreOriginal): string
    {
        $extension = pathinfo($nombreOriginal, PATHINFO_EXTENSION);
        $uuid = (string) Str::uuid();

        return $extension !== '' ? "{$uuid}.{$extension}" : $uuid;
    }

    public function rutaPlantilla(string $nombreInterno): string
    {
        return "plantillas/{$nombreInterno}";
    }

    public function rutaGenerado(string $nombreInterno): string
    {
        return "documentos-generados/{$nombreInterno}";
    }

    public function guardar(UploadedFile $archivo, string $rutaDestino): string
    {
        $carpeta = dirname($rutaDestino);
        $nombre = basename($rutaDestino);

        $this->disco()->putFileAs($carpeta, $archivo, $nombre);

        return $rutaDestino;
    }

    public function guardarContenido(string $rutaDestino, string $contenido): void
    {
        $this->disco()->put($rutaDestino, $contenido);
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
