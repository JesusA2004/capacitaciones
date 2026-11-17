<?php

namespace App\Console\Commands;

use App\Services\EvaluacionesTecnicas\ProgramacionEvaluacionTecnicaService;
use Illuminate\Console\Command;

/**
 * Programado diario (routes/console.php, withoutOverlapping + onOneServer).
 * Genera las evaluaciones técnicas («Seguimiento N») cuyo día desde el
 * ingreso ya llegó, cancela las de quien ya no está en el puesto y avisa las
 * vencidas. Idempotente: ver ProgramacionEvaluacionTecnicaService.
 */
class ProgramarEvaluacionesTecnicasCommand extends Command
{
    protected $signature = 'evaluaciones-tecnicas:programar';

    protected $description = 'Genera las evaluaciones técnicas por puesto (Seguimiento 15 / 30 / 60…) que ya tocan y avisa a quien las contesta.';

    public function handle(ProgramacionEvaluacionTecnicaService $programacion): int
    {
        $resultado = $programacion->programar();

        $this->info(sprintf(
            'Generadas: %d · sin formulario publicado: %d · canceladas: %d · vencidas avisadas: %d',
            $resultado['generadas'],
            $resultado['sin_formulario'],
            $resultado['canceladas'],
            $resultado['vencidas'],
        ));

        return self::SUCCESS;
    }
}
