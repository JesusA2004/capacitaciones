<?php

namespace App\Services\DocumentosMaestros;

use App\Models\DocumentTemplate;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Dónde viven los archivos de los documentos maestros (disco privado
 * `nas`, nunca público):
 *
 *   documentos-maestros/originales/{sha256}.{ext}     ORIGINAL inmutable
 *   documentos-maestros/masters/{familia}/v{n}-{hash}.{ext}  MASTER técnico
 *
 * El original se guarda una sola vez por hash (dos archivos idénticos con
 * distinto nombre comparten el mismo original) y nunca se sobrescribe.
 */
class AlmacenMaestrosService
{
    public function disco(): Filesystem
    {
        return Storage::disk($this->nombreDisco());
    }

    public function nombreDisco(): string
    {
        return (string) config('documentos_maestros.disk', 'nas');
    }

    public function guardarOriginal(string $contenido, string $extension): string
    {
        $hash = hash('sha256', $contenido);
        $ruta = sprintf('%s/originales/%s.%s', config('documentos_maestros.carpeta', 'documentos-maestros'), $hash, strtolower($extension));

        // Inmutable: si ya existe (mismo hash = mismo contenido) no se toca.
        if (! $this->disco()->exists($ruta)) {
            $this->disco()->put($ruta, $contenido);
        }

        return $ruta;
    }

    public function guardarMaster(string $familia, int $version, string $contenido, string $extension): string
    {
        $ruta = sprintf(
            '%s/masters/%s/v%d-%s.%s',
            config('documentos_maestros.carpeta', 'documentos-maestros'),
            str_replace(['/', '\\', '..'], '_', $familia),
            $version,
            substr(hash('sha256', $contenido), 0, 12),
            strtolower($extension),
        );

        $this->disco()->put($ruta, $contenido);

        return $ruta;
    }

    public function original(DocumentTemplate $master): string
    {
        return $this->leer($master->original_disk ?? $this->nombreDisco(), $master->original_path, 'original');
    }

    public function master(DocumentTemplate $master): string
    {
        return $this->leer($master->disk ?? $this->nombreDisco(), $master->path, 'master');
    }

    private function leer(string $disco, ?string $ruta, string $que): string
    {
        if ($ruta === null) {
            throw new RuntimeException("El documento maestro no tiene archivo {$que}.");
        }

        $contenido = Storage::disk($disco)->get($ruta);

        if ($contenido === null) {
            throw new RuntimeException("El archivo {$que} del documento maestro no está disponible en el almacenamiento.");
        }

        return $contenido;
    }
}
