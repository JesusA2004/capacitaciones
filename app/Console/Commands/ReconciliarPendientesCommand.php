<?php

namespace App\Console\Commands;

use App\Services\Tareas\ReconciliacionPendientesService;
use Illuminate\Console\Command;

/**
 * Cierra los pendientes abiertos que ya se cumplieron o cuyo sujeto ya no
 * aplica (borrado, persona dada de baja, solicitud cancelada…). Corre cada
 * 5 minutos (routes/console.php) y al abrir «Mis pendientes». Idempotente.
 * Ver docs/PENDIENTES.md y App\Services\Tareas\ReconciliacionPendientesService.
 */
class ReconciliarPendientesCommand extends Command
{
    protected $signature = 'pendientes:reconciliar';

    protected $description = 'Cierra los pendientes que ya se cumplieron o ya no aplican (idempotente)';

    public function handle(ReconciliacionPendientesService $reconciliacion): int
    {
        $resultado = $reconciliacion->reconciliar();

        $this->line(sprintf('Pendientes abiertos revisados: %d · cerrados porque ya no aplicaban: %d', $resultado['revisados'], $resultado['resueltos']));

        foreach ($resultado['motivos'] as $motivo => $total) {
            $this->line(sprintf('  · %s (%d)', $motivo, $total));
        }

        return self::SUCCESS;
    }
}
