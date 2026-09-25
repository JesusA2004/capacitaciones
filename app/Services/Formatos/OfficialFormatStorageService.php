<?php

namespace App\Services\Formatos;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Única puerta de entrada al almacenamiento de plantillas oficiales (disco
 * 'nas', config('formatos_oficiales.disk')): archivos fuente de cada
 * versión, su PDF base normalizado y los PDF generados que no van a un
 * expediente (candidatos). Los nombres de archivo nunca vienen del usuario.
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

    public function rutaFuenteVersion(int $formatoId, int $numero, string $extension): string
    {
        return sprintf('formatos-oficiales/plantillas/%d/v%d/fuente-%s.%s', $formatoId, $numero, Str::uuid(), $extension);
    }

    public function rutaBaseVersion(int $formatoId, int $numero): string
    {
        return sprintf('formatos-oficiales/plantillas/%d/v%d/base-%s.pdf', $formatoId, $numero, Str::uuid());
    }

    public function rutaGenerado(): string
    {
        return 'formatos-oficiales/generados/'.Str::uuid().'.pdf';
    }

    public function guardarContenido(string $rutaDestino, string $contenido): void
    {
        $this->disco()->put($rutaDestino, $contenido);

        if (! $this->disco()->exists($rutaDestino)) {
            throw new RuntimeException('No se pudo guardar el archivo en el almacenamiento.');
        }
    }

    public function leer(string $ruta): string
    {
        $contenido = $this->disco()->get($ruta);

        if ($contenido === null) {
            throw new RuntimeException('El archivo de la plantilla no está disponible en el almacenamiento.');
        }

        return $contenido;
    }

    /**
     * Copia a un archivo temporal local (PhpWord/FPDI/OCR necesitan una ruta
     * real). Quien llama lo borra.
     */
    public function aTemporal(string $ruta, string $extension): string
    {
        $temporal = sys_get_temp_dir().DIRECTORY_SEPARATOR.Str::uuid().'.'.$extension;
        file_put_contents($temporal, $this->leer($ruta));

        return $temporal;
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

        abort_unless($adaptador->exists($ruta), 404, 'El archivo no está disponible.');

        return $adaptador->response($ruta, null, ['X-Content-Type-Options' => 'nosniff', ...$headers]);
    }
}
