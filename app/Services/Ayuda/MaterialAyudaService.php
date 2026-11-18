<?php

namespace App\Services\Ayuda;

use App\Enums\EstadoPublicacionMaterialAyuda;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Material de apoyo del centro de Ayuda (videos e infografías oficiales).
 *
 * El catálogo vive en config/ayuda_material.php; los archivos originales en
 * docs/ayuda. `publicar()` los copia al disco privado (idempotente: compara
 * tamaño y huella SHA-256) y la pantalla /ayuda/material solo lista lo que
 * de verdad está publicado. Lo usan el comando `ayuda:publicar-material` y
 * AyudaController. Ver docs/AYUDA_MATERIAL.md.
 */
class MaterialAyudaService
{
    /**
     * Catálogo completo, en orden.
     *
     * @return list<MaterialAyuda>
     */
    public function materiales(): array
    {
        $configurados = config('ayuda_material.materiales', []);

        if (! is_array($configurados)) {
            return [];
        }

        $materiales = [];
        foreach ($configurados as $datos) {
            if (is_array($datos)) {
                $materiales[] = MaterialAyuda::desdeConfig($datos);
            }
        }

        usort($materiales, fn (MaterialAyuda $a, MaterialAyuda $b): int => $a->orden <=> $b->orden);

        return $materiales;
    }

    public function buscar(string $slug): ?MaterialAyuda
    {
        foreach ($this->materiales() as $material) {
            if ($material->slug === $slug) {
                return $material;
            }
        }

        return null;
    }

    public function rutaOrigen(MaterialAyuda $material): string
    {
        return sprintf('%s/%s', rtrim((string) config('ayuda_material.origen'), '/\\'), $material->archivo);
    }

    /** Ruta absoluta del archivo publicado en el disco privado. */
    public function rutaPublicada(MaterialAyuda $material): string
    {
        return $this->disco()->path($this->rutaRelativa($material));
    }

    public function estaPublicado(MaterialAyuda $material): bool
    {
        return File::isFile($this->rutaPublicada($material));
    }

    /**
     * Copia cada material de docs/ayuda al disco privado. Sin --forzar, el
     * que ya está publicado con el mismo tamaño y huella no se toca.
     *
     * @return list<array{material: MaterialAyuda, estado: EstadoPublicacionMaterialAyuda, bytes: int|null}>
     */
    public function publicar(bool $forzar = false): array
    {
        $resultados = [];

        foreach ($this->materiales() as $material) {
            $origen = $this->rutaOrigen($material);

            if (! File::isFile($origen)) {
                $resultados[] = ['material' => $material, 'estado' => EstadoPublicacionMaterialAyuda::Faltante, 'bytes' => null];

                continue;
            }

            $destino = $this->rutaPublicada($material);
            $existia = File::isFile($destino);

            if ($existia && ! $forzar && $this->mismoContenido($origen, $destino)) {
                $resultados[] = ['material' => $material, 'estado' => EstadoPublicacionMaterialAyuda::SinCambios, 'bytes' => File::size($destino)];

                continue;
            }

            File::ensureDirectoryExists(dirname($destino));

            // Copia a un temporal y renombra: nadie descarga un archivo a medias.
            $temporal = sprintf('%s.tmp', $destino);
            File::copy($origen, $temporal);
            File::move($temporal, $destino);

            $resultados[] = [
                'material' => $material,
                'estado' => $existia ? EstadoPublicacionMaterialAyuda::Actualizado : EstadoPublicacionMaterialAyuda::Publicado,
                'bytes' => File::size($destino),
            ];
        }

        return $resultados;
    }

    /**
     * Materiales publicados, listos para la pantalla.
     *
     * @return list<array{slug: string, titulo: string, descripcion: string, tipo: string, tipoEtiqueta: string, tema: string, temaEtiqueta: string, audiencia: string, bytes: int, url: string, descargar: string}>
     */
    public function publicados(): array
    {
        $temas = config('ayuda_material.temas', []);
        $publicados = [];

        foreach ($this->materiales() as $material) {
            if (! $this->estaPublicado($material)) {
                continue;
            }

            $publicados[] = [
                'slug' => $material->slug,
                'titulo' => $material->titulo,
                'descripcion' => $material->descripcion,
                'tipo' => $material->tipo->value,
                'tipoEtiqueta' => $material->tipo->etiqueta(),
                'tema' => $material->tema,
                'temaEtiqueta' => is_array($temas) ? (string) ($temas[$material->tema] ?? $material->tema) : $material->tema,
                'audiencia' => $material->audiencia,
                'bytes' => File::size($this->rutaPublicada($material)),
                'url' => route('ayuda.material.archivo', $material->slug),
                'descargar' => route('ayuda.material.archivo', ['material' => $material->slug, 'descargar' => 1]),
            ];
        }

        return $publicados;
    }

    /** Nombre con el que se descarga (el original, para reconocerlo). */
    public function nombreDescarga(MaterialAyuda $material): string
    {
        return $material->archivo;
    }

    public function mime(MaterialAyuda $material): string
    {
        return $material->tipo->mime();
    }

    private function mismoContenido(string $origen, string $destino): bool
    {
        return File::size($origen) === File::size($destino)
            && hash_file('sha256', $origen) === hash_file('sha256', $destino);
    }

    private function rutaRelativa(MaterialAyuda $material): string
    {
        return sprintf('%s/%s', trim((string) config('ayuda_material.carpeta', 'ayuda'), '/'), $material->archivo);
    }

    private function disco(): Filesystem
    {
        return Storage::disk((string) config('ayuda_material.disco', 'local'));
    }
}
