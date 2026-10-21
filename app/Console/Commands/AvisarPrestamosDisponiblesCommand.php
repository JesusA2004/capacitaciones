<?php

namespace App\Console\Commands;

use App\Services\Nomina\PrestamoDisponibleService;
use Illuminate\Console\Command;

/**
 * Programado diario (routes/console.php, withoutOverlapping + onOneServer).
 * Avisa a cada colaborador que acaba de cumplir la antigüedad mínima para
 * pedir un préstamo. Idempotente: un aviso por persona, nunca repetido.
 */
class AvisarPrestamosDisponiblesCommand extends Command
{
    protected $signature = 'prestamos:avisar-disponibles';

    protected $description = 'Avisa a los colaboradores que ya cumplen la antigüedad para solicitar un préstamo (sin repetir).';

    public function handle(PrestamoDisponibleService $servicio): int
    {
        $resultado = $servicio->avisar();
        $this->info(sprintf('Revisados: %d · avisados: %d', $resultado['revisados'], $resultado['avisados']));

        return self::SUCCESS;
    }
}
