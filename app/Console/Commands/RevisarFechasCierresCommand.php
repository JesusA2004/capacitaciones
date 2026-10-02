<?php

namespace App\Console\Commands;

use App\Services\CierreLaboral\CierreLaboralService;
use Illuminate\Console\Command;

/**
 * Programado diario (routes/console.php, withoutOverlapping + onOneServer).
 * Abre el pendiente "concluir cierre laboral" de los cierres pagados cuya
 * fecha efectiva ya llegó. Idempotente: TareaService no duplica un
 * pendiente abierto del mismo tipo y objeto.
 */
class RevisarFechasCierresCommand extends Command
{
    protected $signature = 'cierres:revisar-fechas';

    protected $description = 'Abre los pendientes de cierres laborales cuya fecha efectiva ya llegó (sin duplicar).';

    public function handle(CierreLaboralService $cierres): int
    {
        $abiertos = $cierres->revisarFechasEfectivas();
        $this->info("Pendientes de cierre por concluir abiertos: {$abiertos}");

        return self::SUCCESS;
    }
}
