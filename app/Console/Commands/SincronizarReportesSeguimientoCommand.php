<?php

namespace App\Console\Commands;

use App\Services\Seguimiento\DirectorioReportesService;
use App\Services\Seguimiento\ReportesApiCliente;
use App\Services\Seguimiento\ReportesNoDisponibleException;
use App\Services\Seguimiento\SeguimientoService;
use Illuminate\Console\Command;

/**
 * Deja listo en la caché de PEOPLE lo que Seguimiento lee de Reportes
 * (docs/SEGUIMIENTO.md): el directorio de PERSONAS y SUCURSALES (con sus
 * rutas) y la actividad del mes más reciente, para que «Vínculos con
 * Reportes» y la Nine Box abran sin esperar a Reportes. Solo lectura; si
 * Reportes no responde se conserva lo último conocido.
 *
 *   php artisan seguimiento:sincronizar-reportes
 */
class SincronizarReportesSeguimientoCommand extends Command
{
    protected $signature = 'seguimiento:sincronizar-reportes';

    protected $description = 'Refresca desde Reportes el directorio de personas/sucursales y precalienta la actividad del mes (Seguimiento)';

    public function handle(DirectorioReportesService $directorio, SeguimientoService $seguimiento, ReportesApiCliente $cliente): int
    {
        if (! config('features.seguimiento') || ! $cliente->estaConfigurado()) {
            $this->info('Seguimiento apagado o Reportes sin configurar: nada que sincronizar.');

            return self::SUCCESS;
        }

        try {
            $inicio = microtime(true);
            $datos = $directorio->directorio(fresco: true);
            $personas = count(array_filter($datos['personas'], fn (array $p): bool => $p['es_persona']));
            $this->info(sprintf('Directorio: %d personas, %d registros que no son personas, %d sucursales (%.1f s).', $personas, count($datos['personas']) - $personas, count($datos['sucursales']), microtime(true) - $inicio));

            $mes = $seguimiento->periodo(null)['mes'];

            if ($mes !== null) {
                $inicio = microtime(true);
                $seguimiento->actividadGestoresMes($mes['id']);
                $this->info(sprintf('Actividad de %s lista (%.1f s).', $mes['etiqueta'], microtime(true) - $inicio));
            }
        } catch (ReportesNoDisponibleException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
