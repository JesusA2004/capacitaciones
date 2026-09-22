<?php

namespace App\Console\Commands;

use App\Services\Contratos\VencimientoContratosService;
use App\Services\Tareas\SincronizacionTareasService;
use Illuminate\Console\Command;

/**
 * Programado diario (routes/console.php, withoutOverlapping + onOneServer).
 * Idempotente: ver App\Services\Contratos\VencimientoContratosService.
 */
class RevisarVencimientosContratosCommand extends Command
{
    protected $signature = 'contratos:revisar-vencimientos';

    protected $description = 'Crea evaluaciones, tareas y avisos de contratos próximos a vencer (sin duplicar).';

    public function handle(VencimientoContratosService $vencimientos, SincronizacionTareasService $sincronizacion): int
    {
        $resultado = $vencimientos->revisar();

        $this->info(sprintf(
            'Contratos revisados: %d · avisos nuevos: %d · errores: %d',
            $resultado['revisados'],
            $resultado['avisados'],
            $resultado['errores'],
        ));

        $sincronizados = $sincronizacion->sincronizarExpedientesIncompletos();
        $this->info("Pendientes de expediente sincronizados: {$sincronizados}");

        return $resultado['errores'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
