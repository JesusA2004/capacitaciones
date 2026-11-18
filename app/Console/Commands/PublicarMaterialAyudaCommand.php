<?php

namespace App\Console\Commands;

use App\Enums\EstadoPublicacionMaterialAyuda;
use App\Services\Ayuda\MaterialAyudaService;
use Illuminate\Console\Command;

/**
 * Publica en PEOPLE el material de apoyo del centro de Ayuda (videos e
 * infografías de docs/ayuda) copiándolo al disco privado. Idempotente: lo
 * que ya está igual no se toca; `--forzar` lo reemplaza todo. Ver
 * docs/AYUDA_MATERIAL.md.
 */
class PublicarMaterialAyudaCommand extends Command
{
    protected $signature = 'ayuda:publicar-material
        {--forzar : Reemplaza los archivos aunque ya estén publicados sin cambios}';

    protected $description = 'Publica los videos e infografías de docs/ayuda en el centro de Ayuda (/ayuda/material)';

    public function handle(MaterialAyudaService $material): int
    {
        $this->info(sprintf('Origen: %s', (string) config('ayuda_material.origen')));

        $resultados = $material->publicar((bool) $this->option('forzar'));

        $this->table(
            ['Material', 'Tipo', 'Archivo', 'Tamaño', 'Estado'],
            array_map(fn (array $r): array => [
                $r['material']->titulo,
                $r['material']->tipo->etiqueta(),
                $r['material']->archivo,
                $r['bytes'] === null ? '—' : sprintf('%.1f MB', $r['bytes'] / 1048576),
                $r['estado']->etiqueta(),
            ], $resultados),
        );

        $faltantes = array_values(array_filter(
            $resultados,
            fn (array $r): bool => $r['estado'] === EstadoPublicacionMaterialAyuda::Faltante,
        ));

        if ($faltantes !== []) {
            foreach ($faltantes as $r) {
                $this->error(sprintf('Falta %s en %s', $r['material']->archivo, (string) config('ayuda_material.origen')));
            }
            $this->error(sprintf('%d de %d materiales no se pudieron publicar.', count($faltantes), count($resultados)));

            return self::FAILURE;
        }

        $this->info(sprintf('Listo: %d materiales disponibles en /ayuda/material.', count($resultados)));

        return self::SUCCESS;
    }
}
