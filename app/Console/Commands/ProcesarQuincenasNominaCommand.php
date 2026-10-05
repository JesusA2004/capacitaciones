<?php

namespace App\Console\Commands;

use App\Services\Nomina\NominaQuincenalService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * php artisan nomina:procesar-quincenas [--fecha=AAAA-MM-DD]
 *
 * Corre diario (routes/console.php). Idempotente:
 *  1. Si faltan `nomina.quincenal.dias_anticipacion` días o menos para el
 *     pago de la quincena en curso (o de la siguiente), prepara sus
 *     recibos como borrador (a quien ya tiene recibo no se le duplica).
 *  2. Si `nomina.quincenal.emision_automatica` está activa, emite todos
 *     los borradores cuya fecha de pago ya llegó (PDF + aviso).
 * Ver docs/NOMINA_QUINCENAL.md.
 */
class ProcesarQuincenasNominaCommand extends Command
{
    protected $signature = 'nomina:procesar-quincenas {--fecha= : Fecha de referencia (por defecto hoy, hora de CDMX)}';

    protected $description = 'Prepara los recibos quincenales como borrador antes del pago y los emite en la fecha de pago';

    public function handle(NominaQuincenalService $quincenas): int
    {
        $hoy = $this->option('fecha')
            ? CarbonImmutable::parse((string) $this->option('fecha'))->startOfDay()
            : CarbonImmutable::now('America/Mexico_City')->startOfDay();
        $anticipacion = max(0, (int) config('nomina.quincenal.dias_anticipacion', 3));

        $responsable = $quincenas->responsableAutomatico();

        foreach ([$quincenas->periodo($hoy), $quincenas->siguiente($quincenas->periodo($hoy))] as $periodo) {
            if ($hoy->diffInDays($periodo['pago'], false) > $anticipacion) {
                continue;
            }

            $resultado = $quincenas->preparar($periodo);
            $this->info(sprintf('%s: %d borrador(es) nuevos, %d ya existían.', $periodo['etiqueta'], $resultado['creados'], $resultado['existentes']));

            foreach ($resultado['omitidos'] as $omitido) {
                $this->warn(sprintf(' - Sin recibo: %s (#%d) — %s', $omitido['nombre'], $omitido['colaborador_id'], $omitido['motivo']));
            }
        }

        if (! config('nomina.quincenal.emision_automatica', true)) {
            $this->line('Emisión automática desactivada (NOMINA_EMISION_AUTOMATICA=false): RH emite desde «Recibos de nómina».');

            return self::SUCCESS;
        }

        if ($responsable === null) {
            $this->error('No hay ningún usuario activo con permiso nomina.recibos.crear para emitir a su nombre; no se emitió nada.');

            return self::FAILURE;
        }

        $emitidos = $quincenas->emitirVencidos($hoy, $responsable);
        $this->info(sprintf('%d recibo(s) emitido(s) con fecha de pago al %s.', $emitidos, $hoy->format('d/m/Y')));

        return self::SUCCESS;
    }
}
