<?php

namespace App\Console\Commands;

use App\Services\Notificaciones\ReconciliadorNotificacionesService;
use Illuminate\Console\Command;

/**
 * Red de seguridad de los avisos: repone, una sola vez, el aviso de un
 * pendiente reciente que nunca le llegó a su responsable. Corre cada minuto
 * (routes/console.php). Idempotente: correrlo de más no duplica nada.
 * Ver App\Services\Notificaciones\ReconciliadorNotificacionesService.
 */
class ReconciliarNotificacionesCommand extends Command
{
    protected $signature = 'notificaciones:reconciliar {--horas=48 : Ventana de pendientes recientes a revisar}';

    protected $description = 'Crea el aviso faltante de pendientes abiertos recientes (idempotente, nunca duplica)';

    public function handle(ReconciliadorNotificacionesService $reconciliador): int
    {
        $horas = max(1, min(168, (int) $this->option('horas')));
        $resultado = $reconciliador->reconciliar($horas);

        $this->line(sprintf('Pendientes revisados: %d · avisos repuestos: %d', $resultado['revisados'], $resultado['creados']));

        return self::SUCCESS;
    }
}
