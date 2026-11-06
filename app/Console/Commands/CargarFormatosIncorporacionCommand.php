<?php

namespace App\Console\Commands;

use App\Models\DocumentTemplate;
use App\Services\DocumentosMaestros\CatalogoMaestrosService;
use App\Services\DocumentosMaestros\ImportadorFormatosJuridicosService;
use Illuminate\Console\Command;
use Throwable;

/**
 * php artisan people:cargar-formatos-incorporacion [--ruta=docs/formatos] [--simular]
 *
 * Carga como documento ORIGINAL de «Solicitud de empleo · General» y
 * «Estudio socioeconómico · General» los PDFs oficiales que viajan en Git
 * (docs/formatos/). Reutiliza el importador de documentos maestros (mismo
 * camino que people:importar-formatos-juridicos): el archivo se reconoce por
 * su SHA-256 del registro config/documentos_maestros.php, se guarda inmutable
 * en el NAS y la versión queda con su mapa de espacios ya medido
 * ($solicitudEmpleoCampos / $estudioCampos). Idempotente: correrlo otra vez
 * no duplica nada.
 *
 * Un solo formato para TODOS los puestos (grupos = null): el puesto y la
 * sucursal salen del colaborador, que los toma de su vacante al contratarse.
 */
class CargarFormatosIncorporacionCommand extends Command
{
    protected $signature = 'people:cargar-formatos-incorporacion
                            {--ruta= : Carpeta con los PDFs (por defecto docs/formatos)}
                            {--simular : Solo reporta; no guarda nada}';

    protected $description = 'Carga los originales de Solicitud de empleo y Estudio socioeconómico (General) desde docs/formatos (idempotente).';

    public function handle(ImportadorFormatosJuridicosService $importador, CatalogoMaestrosService $catalogo): int
    {
        $ruta = (string) ($this->option('ruta') ?: base_path('docs/formatos'));
        $claves = array_map('strval', (array) config('documentos_maestros.documentos_incorporacion', []));
        $familias = [];

        foreach ($catalogo->definicionesLegado() as $familia => $definicion) {
            if (in_array((string) ($definicion['clave'] ?? ''), $claves, true)) {
                $familias[] = (string) $familia;
            }
        }

        $this->info("Carpeta: {$ruta}");
        $this->line('Familias: '.implode(', ', $familias));

        try {
            $resultado = $importador->importarCarpeta($ruta, null, (bool) $this->option('simular'), $familias);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($resultado['versiones'] !== []) {
            $this->table(
                ['Familia', 'Versión', 'Estado', 'Activo', 'Detectados', 'Mapeados', 'Pendientes'],
                array_map(fn (array $v): array => [
                    $v['familia'], $v['version'], $v['estado'] ?? $v['accion'] ?? '', isset($v['activo']) ? ($v['activo'] ? 'sí' : 'no') : '',
                    $v['detectados'] ?? '', $v['mapeados'] ?? '', $v['pendientes'] ?? '',
                ], $resultado['versiones']),
            );
        }

        foreach ($resultado['anomalias'] as $anomalia) {
            $this->warn(' - '.$anomalia);
        }

        $faltan = array_diff($familias, array_column($resultado['versiones'], 'familia'));

        if ($faltan !== []) {
            $this->error('No se encontró el PDF original de: '.implode(', ', $faltan).'. Revisa que docs/formatos esté actualizado (git pull).');

            return self::FAILURE;
        }

        // Qué falta para activarla (si no quedó activa sola).
        if (! $this->option('simular')) {
            foreach ($familias as $familia) {
                $master = DocumentTemplate::query()->where('familia', $familia)->orderByDesc('version')->first();

                if ($master !== null && ! $master->activo) {
                    $bloqueos = array_column($importador->bloqueosActivacion($master), 'mensaje');
                    $this->warn(sprintf('%s v%d quedó INACTIVA: %s Ábrela en Documentos maestros → Editar para revisarla y activarla.', $familia, $master->version, implode(' ', $bloqueos)));
                }
            }
        }

        return self::SUCCESS;
    }
}
