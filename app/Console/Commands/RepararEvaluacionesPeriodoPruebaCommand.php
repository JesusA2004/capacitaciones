<?php

namespace App\Console\Commands;

use App\Services\Contratos\ReparacionEvaluacionesPeriodoPruebaService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Retira las evaluaciones de periodo de prueba (FO-RH-EPP-001) asignadas a
 * quien no cumplía la regla (aún en inducción / antes de su fecha). Simula por
 * omisión; `--aplicar` escribe. Toda la lógica vive en
 * ReparacionEvaluacionesPeriodoPruebaService. No avisa a nadie.
 */
class RepararEvaluacionesPeriodoPruebaCommand extends Command
{
    protected $signature = 'people:reparar-evaluaciones-periodo-prueba
        {--aplicar : Retira de verdad (sin esta opción solo simula y lista)}
        {--incluir-fecha : También retira las asignadas antes de su fecha a quien ya aprobó su inducción (por omisión solo se listan)}';

    protected $description = 'Retira las evaluaciones de periodo de prueba asignadas a quien aún no estaba en capacitación inicial o no había llegado a su fecha (solo las no contestadas).';

    public function handle(ReparacionEvaluacionesPeriodoPruebaService $reparacion): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $casos = $reparacion->casos((bool) $this->option('incluir-fecha'));

        $this->line($aplicar ? '<info>MODO APLICAR</info>: se retiran las evaluaciones sin contestar.' : '<comment>SIMULACIÓN</comment>: no se modifica nada (agrega --aplicar para escribir).');

        if ($casos === []) {
            $this->info('No hay evaluaciones de periodo de prueba asignadas fuera de regla.');

            return self::SUCCESS;
        }

        $retiradas = 0;
        $fallidas = 0;
        $filas = [];

        foreach ($casos as $caso) {
            $resultado = match ($caso['accion']) {
                ReparacionEvaluacionesPeriodoPruebaService::ACCION_RETIRAR => $aplicar ? 'RETIRADA' : 'Se retiraría',
                ReparacionEvaluacionesPeriodoPruebaService::ACCION_REVISAR => 'REVISAR A MANO',
                default => 'Se deja',
            };

            if ($aplicar && $caso['accion'] === ReparacionEvaluacionesPeriodoPruebaService::ACCION_RETIRAR) {
                try {
                    if ($reparacion->retirar($caso['evaluacion_id'], $caso['codigo'])) {
                        $retiradas++;
                    } else {
                        $resultado = 'NO RETIRADA (cambió mientras tanto)';
                        $fallidas++;
                    }
                } catch (Throwable $e) {
                    $resultado = sprintf('ERROR: %s', $e->getMessage());
                    $fallidas++;
                }
            }

            $filas[] = [
                $caso['evaluacion_id'],
                sprintf('%s (%s)', $caso['colaborador'], $caso['numero_empleado'] ?? sprintf('#%d', $caso['colaborador_id'])),
                sprintf('%s · %s', $caso['puesto'] ?? 'Sin puesto', $caso['sucursal'] ?? 'Sin sucursal'),
                $caso['fase'],
                $caso['estado'],
                $caso['asignada_en'] ?? '—',
                $caso['fin_periodo'] ?? '—',
                $caso['motivo'],
                $resultado,
            ];
        }

        $this->table(['Eval.', 'Colaborador', 'Puesto · Sucursal', 'Fase real', 'Estado', 'Asignada', 'Fin de periodo', 'Por qué no procedía', 'Resultado'], $filas);

        $porAccion = array_count_values(array_column($casos, 'accion'));
        $this->line(sprintf(
            'Fuera de regla: %d · sin contestar a retirar: %d · contestadas (revisar a mano): %d · solo antes de su fecha (se dejan): %d',
            count($casos),
            $porAccion[ReparacionEvaluacionesPeriodoPruebaService::ACCION_RETIRAR] ?? 0,
            $porAccion[ReparacionEvaluacionesPeriodoPruebaService::ACCION_REVISAR] ?? 0,
            $porAccion[ReparacionEvaluacionesPeriodoPruebaService::ACCION_OMITIR] ?? 0,
        ));

        foreach ($casos as $caso) {
            if ($caso['accion'] === ReparacionEvaluacionesPeriodoPruebaService::ACCION_REVISAR) {
                $this->warn(sprintf('  Revisar a mano · evaluación #%d de %s: %s', $caso['evaluacion_id'], $caso['colaborador'], $caso['detalle_accion']));
            }
        }

        if ($aplicar) {
            $this->info(sprintf('Retiradas: %d%s. El tablero de candidatos ya los muestra en su fase real.', $retiradas, $fallidas > 0 ? sprintf(' · no retiradas: %d', $fallidas) : ''));
        } else {
            $this->comment('Nada se modificó. Ejecuta de nuevo con --aplicar para retirar las marcadas «Se retiraría».');
        }

        return $fallidas > 0 ? self::FAILURE : self::SUCCESS;
    }
}
