<?php

namespace App\Console\Commands;

use App\Enums\PeriodicidadNomina;
use App\Services\Nomina\LoteNominaService;
use App\Services\Nomina\NominaQuincenalService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * php artisan nomina:procesar-quincenas [--fecha=AAAA-MM-DD]
 *
 * Corre diario (routes/console.php). Idempotente:
 *  1. Si faltan `nomina.quincenal.dias_anticipacion` días o menos para el
 *     pago de la quincena en curso (o de la siguiente), PREPARA su lote de
 *     recibos (LoteNominaService): borradores no visibles, sin aviso. Si ya
 *     hay un lote en revisión para ese periodo, no crea otro.
 *  2. Solo si `nomina.quincenal.emision_automatica` está activa (por
 *     defecto NO), emite los borradores cuya fecha de pago ya llegó.
 * Ver docs/NOMINA_QUINCENAL.md.
 */
class ProcesarQuincenasNominaCommand extends Command
{
    protected $signature = 'nomina:procesar-quincenas {--fecha= : Fecha de referencia (por defecto hoy, hora de CDMX)}';

    protected $description = 'Prepara el lote quincenal de recibos (sin publicar) antes del pago';

    public function handle(NominaQuincenalService $quincenas, LoteNominaService $lotes): int
    {
        $hoy = $this->option('fecha')
            ? CarbonImmutable::parse((string) $this->option('fecha'))->startOfDay()
            : CarbonImmutable::now('America/Mexico_City')->startOfDay();
        $anticipacion = max(0, (int) config('nomina.quincenal.dias_anticipacion', 3));
        $responsable = $quincenas->responsableAutomatico();

        if ($responsable === null) {
            $this->error('No hay ningún usuario activo con permiso nomina.recibos.crear a cuyo nombre preparar el lote; no se hizo nada.');

            return self::FAILURE;
        }

        foreach ([$quincenas->periodo($hoy), $quincenas->siguiente($quincenas->periodo($hoy))] as $periodo) {
            if ($hoy->diffInDays($periodo['pago'], false) > $anticipacion) {
                continue;
            }

            try {
                $lote = $lotes->preparar(PeriodicidadNomina::Quincenal, $periodo['inicio'], $responsable);
                $this->info(sprintf('%s: lote %s preparado (%d de %d, %d error(es)). Pendiente de revisión y emisión por RH.', $periodo['etiqueta'], $lote->folio, $lote->preparados, $lote->esperados, count($lote->errores ?? [])));
            } catch (ValidationException $e) {
                $this->line(sprintf('%s: %s', $periodo['etiqueta'], collect($e->errors())->flatten()->implode(' ')));
            }
        }

        if (! config('nomina.quincenal.emision_automatica', false)) {
            $this->line('Emisión automática desactivada: RH revisa y emite el lote desde «Recibos de nómina».');

            return self::SUCCESS;
        }

        $emitidos = $quincenas->emitirVencidos($hoy, $responsable);
        $this->info(sprintf('%d recibo(s) emitido(s) con fecha de pago al %s.', $emitidos, $hoy->format('d/m/Y')));

        return self::SUCCESS;
    }
}
