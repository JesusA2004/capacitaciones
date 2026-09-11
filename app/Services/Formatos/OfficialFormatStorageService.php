<?php

namespace App\Services\Formatos;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unica puerta de entrada al almacenamiento de formatos oficiales (disco
 * 'nas', config('formatos_oficiales.disk')) y de los PDF generados a partir
 * de ellos. Espejo deliberado de App\Services\Plantillas\PlantillaStorageService.
 */
class OfficialFormatStorageService
{
    public function disco(): Filesystem
    {
        return Storage::disk(config('formatos_oficiales.disk'));
    }

    public function rutaOriginal(string $slug): string
    {
        return "formatos-oficiales/originales/{$slug}.pdf";
    }

    public function rutaGenerado(): string
    {
        return 'formatos-oficiales/generados/'.Str::uuid().'.pdf';
    }

    public function guardarContenido(string $rutaDestino, string $contenido): void
    {
        $this->disco()->put($rutaDestino, $contenido);
    }

    public function leer(string $ruta): string
    {
        $contenido = $this->disco()->get($ruta);

        return $contenido ?? '';
    }

    public function existe(string $ruta): bool
    {
        return $this->disco()->exists($ruta);
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
