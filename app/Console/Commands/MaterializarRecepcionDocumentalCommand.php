<?php

namespace App\Console\Commands;

use App\Services\RecepcionDocumental\PeriodoRecepcion;
use App\Services\RecepcionDocumental\RecepcionDocumentalService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Calcula (materializa) el corte de Recepción documental del mes en curso
 * en hora de México, o del mes indicado. Idempotente: correrlo varias veces
 * no duplica renglones. Un corte cerrado nunca se recalcula (se omite).
 */
class MaterializarRecepcionDocumentalCommand extends Command
{
    protected $signature = 'recepcion-documental:materializar {--periodo= : Mes AAAA-MM (por defecto, el mes en curso en México)} {--anterior : Además recalcula el mes anterior si sigue abierto}';

    protected $description = 'Calcula los originales esperados del corte mensual de Recepción documental RH (idempotente).';

    public function handle(RecepcionDocumentalService $servicio): int
    {
        try {
            $periodo = is_string($this->option('periodo')) && $this->option('periodo') !== ''
                ? PeriodoRecepcion::desdeClave($this->option('periodo'))
                : PeriodoRecepcion::actual();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $periodos = [$periodo];

        if ((bool) $this->option('anterior')) {
            $anterior = $periodo->inicio()->subMonthNoOverflow();
            $periodos[] = PeriodoRecepcion::de($anterior->year, $anterior->month);
        }

        foreach ($periodos as $p) {
            try {
                ['corte' => $corte, 'conteo' => $conteo] = $servicio->generar($p, null, true);
            } catch (ValidationException $e) {
                $this->warn(sprintf('%s: %s', $p->clave(), collect($e->errors())->flatten()->first()));

                continue;
            }

            if ($conteo === null) {
                $this->line(sprintf('%s: corte cerrado, no se recalcula.', $p->clave()));

                continue;
            }

            $this->info(sprintf(
                '%s (%s): %d nuevo(s), %d ligado(s) a su versión digital, %d a «No aplica», %d reactivado(s), %d con cantidad actualizada. Estado: %s.',
                $p->clave(),
                $p->etiqueta(),
                $conteo['nuevos'],
                $conteo['ligados'],
                $conteo['no_aplica'],
                $conteo['reactivados'],
                $conteo['actualizados'],
                $corte->estado->etiqueta(),
            ));
        }

        return self::SUCCESS;
    }
}
