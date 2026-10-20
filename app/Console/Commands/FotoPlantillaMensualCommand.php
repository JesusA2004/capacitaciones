<?php

namespace App\Console\Commands;

use App\Services\Bajas\PlantillaMensualService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Foto de la plantilla por sucursal (denominador de la rotación,
 * docs/BAJAS_Y_CAUSALES.md). Programado en routes/console.php: día 1 a las
 * 00:15 (activos al inicio) y último día del mes a las 23:50 (activos al
 * cierre). Con --momento=auto decide por la fecha de hoy. Idempotente:
 * repetirlo el mismo día solo actualiza la misma fila.
 */
class FotoPlantillaMensualCommand extends Command
{
    protected $signature = 'bajas:foto-plantilla {--momento=auto : inicio, cierre o auto (según el día)}';

    protected $description = 'Toma la foto de activos por sucursal (día 1 = inicio, último día = cierre) en plantilla_mensual.';

    public function handle(PlantillaMensualService $plantilla): int
    {
        $hoy = CarbonImmutable::now('America/Mexico_City')->startOfDay();
        $momento = (string) $this->option('momento');

        if ($momento === 'auto') {
            $momento = match (true) {
                $hoy->day === 1 => 'inicio',
                $hoy->isLastOfMonth() => 'cierre',
                default => '',
            };
        }

        if (! in_array($momento, ['inicio', 'cierre'], true)) {
            $this->info('Hoy no es día 1 ni último día del mes: no hay foto que tomar (usa --momento=inicio|cierre para forzarla).');

            return self::SUCCESS;
        }

        $sucursales = $plantilla->foto($hoy, $momento);
        $this->info(sprintf('Foto de %s de %s: %d sucursal(es).', $momento, $hoy->format('Y-m'), $sucursales));

        return self::SUCCESS;
    }
}
