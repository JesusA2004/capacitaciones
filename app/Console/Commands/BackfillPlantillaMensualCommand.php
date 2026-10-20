<?php

namespace App\Console\Commands;

use App\Services\Bajas\PlantillaMensualService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Reconstruye plantilla_mensual de meses pasados sin foto, desde las
 * fechas de ingreso y baja de los colaboradores (incluidos los dados de
 * baja). Nunca pisa una foto tomada por bajas:foto-plantilla. Por defecto:
 * los últimos 24 meses hasta el mes actual. Ver docs/BAJAS_Y_CAUSALES.md.
 */
class BackfillPlantillaMensualCommand extends Command
{
    protected $signature = 'bajas:plantilla-backfill {--desde= : AAAA-MM (por defecto, hace 23 meses)} {--hasta= : AAAA-MM (por defecto, el mes actual)}';

    protected $description = 'Reconstruye la plantilla mensual por sucursal desde el historial de altas y bajas (sin pisar las fotos).';

    public function handle(PlantillaMensualService $plantilla): int
    {
        $hasta = $this->mes($this->option('hasta')) ?? CarbonImmutable::now()->startOfMonth();
        $desde = $this->mes($this->option('desde')) ?? $hasta->subMonths(23);

        if ($desde->gt($hasta)) {
            $this->error('--desde debe ser anterior o igual a --hasta.');

            return self::FAILURE;
        }

        $resultado = $plantilla->backfill($desde, $hasta);
        $this->info(sprintf('Plantilla reconstruida de %s a %s: %d mes(es), %d fila(s) por sucursal.', $desde->format('Y-m'), $hasta->format('Y-m'), $resultado['meses'], $resultado['filas']));

        if ($resultado['sin_fecha_ingreso'] > 0) {
            $this->warn(sprintf('%d colaborador(es) sin fecha de ingreso no se contaron (complétala en su expediente y vuelve a correr el comando).', $resultado['sin_fecha_ingreso']));
        }

        return self::SUCCESS;
    }

    private function mes(mixed $valor): ?CarbonImmutable
    {
        if (! is_string($valor) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $valor) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m', $valor);
    }
}
