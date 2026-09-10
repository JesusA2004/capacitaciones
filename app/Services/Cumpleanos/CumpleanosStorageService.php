<?php

namespace App\Services\Cumpleanos;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unica puerta de entrada al almacenamiento de tarjetas de cumpleanos (disco
 * 'nas', config('cumpleanos.disk')). Espejo deliberado de
 * App\Services\Expedientes\DocumentoStorageService / CvStorageService:
 * ningun controlador debe llamar Storage::disk() directamente para estos
 * archivos ni exponer la ruta fisica real.
 */
class CumpleanosStorageService
{
    public function disco(): Filesystem
    {
        return Storage::disk(config('cumpleanos.disk'));
    }

    public function rutaTarjeta(int $userId, string $fecha): string
    {
        return "cumpleanos/{$userId}/{$fecha}.png";
    }

    public function guardar(string $ruta, string $contenidoPng): void
    {
        $this->disco()->put($ruta, $contenidoPng);
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
