<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Headcount\HeadcountImportService;
use App\Services\Vacantes\VacanteAutoGenerationService;
use Illuminate\Console\Command;

/**
 * Importa `headcount_targets` desde el Excel de plantilla autorizada (ver
 * docs/HEADCOUNT_Y_VACANTES.md) y sincroniza las vacantes automáticas. Solo
 * importa "plantilla autorizada" — "plantilla actual" nunca se importa, se
 * calcula en vivo (HeadcountService).
 *
 * Idempotente: correrlo varias veces con el mismo archivo no duplica nada
 * (upsert por sucursal+puesto) y solo reporta cambios reales.
 */
class ImportarHeadcountCommand extends Command
{
    /**
     * Ruta por defecto cuando no se pasa {archivo}: el Excel real que RH
     * entregó, ya versionado en el repo (ver docs/HEADCOUNT_Y_VACANTES.md).
     */
    private const RUTA_DEFECTO = 'claude/headcount/HEADCOUNT GENERAL MR LANA 28-08-2026..xlsx';

    protected $signature = 'headcount:importar {archivo? : Ruta al .xlsx de headcount (por defecto, el Excel real en claude/headcount/)}';

    protected $description = 'Importa la plantilla autorizada desde el Excel de headcount y sincroniza vacantes automáticas';

    public function handle(HeadcountImportService $importador, VacanteAutoGenerationService $vacantes): int
    {
        $ruta = $this->argument('archivo') ?? self::RUTA_DEFECTO;
        $rutaResuelta = $this->esRutaAbsoluta($ruta) ? $ruta : base_path($ruta);

        if (! is_file($rutaResuelta)) {
            // Nunca truena el deploy por esto: solo avisa. El Excel puede
            // no existir todavía en un entorno nuevo, o RH aún no lo sube.
            $this->error("No se encontró el archivo de headcount: {$rutaResuelta}");
            $this->line('Coloca el Excel real en esa ruta, o indica otra con: php artisan headcount:importar "ruta/al/archivo.xlsx"');

            return self::FAILURE;
        }

        $usuario = User::query()->role('super_admin')->first() ?? User::query()->firstOrFail();

        $resultado = $importador->importar($rutaResuelta, $usuario);

        $this->info("Headcount importado: {$resultado['creados']} creados, {$resultado['actualizados']} actualizados, {$resultado['sin_cambio']} sin cambio.");

        if ($resultado['sucursales_sin_match'] !== []) {
            $this->warn('Sucursales sin coincidencia en el sistema (pendientes, no se crearon):');
            foreach ($resultado['sucursales_sin_match'] as $nombre) {
                $this->line("  - {$nombre}");
            }
        }

        if ($resultado['puestos_sin_match'] !== []) {
            $this->warn('Modalidades/puestos sin coincidencia (pendientes, no se crearon):');
            foreach ($resultado['puestos_sin_match'] as $nombre) {
                $this->line("  - {$nombre}");
            }
        }

        if ($resultado['conflictos'] !== []) {
            $this->warn('Conflictos entre hojas para el mismo par sucursal/puesto (se conservó el primer valor leído):');
            foreach ($resultado['conflictos'] as $conflicto) {
                $this->line("  - {$conflicto}");
            }
        }

        $sincronizacion = $vacantes->sincronizarTodo();
        $this->info("Vacantes automáticas sincronizadas: {$sincronizacion['abiertas']} abiertas, {$sincronizacion['cerradas']} cerradas.");

        return self::SUCCESS;
    }

    private function esRutaAbsoluta(string $ruta): bool
    {
        return str_starts_with($ruta, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $ruta) === 1;
    }
}
