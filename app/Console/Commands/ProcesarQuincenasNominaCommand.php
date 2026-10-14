<?php

namespace App\Console\Commands;

use App\Enums\PeriodicidadNomina;
use App\Models\User;
use App\Services\Nomina\LoteNominaService;
use App\Services\Nomina\NominaQuincenalService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * php artisan nomina:procesar-quincenas [--fecha=AAAA-MM-DD]
 *
 * Corre diario (routes/console.php). Idempotente:
 *  1. Si faltan `nomina.quincenal.dias_anticipacion` días o menos para el
 *     pago de la quincena en curso (o de la siguiente), PREPARA el lote
 *     QUINCENAL (corporativo) y el SEMANAL (sucursales) con LoteNominaService:
 *     cada grupo de pago lo decide `sucursal.es_corporativo`, nunca se
 *     mezclan en el mismo lote (CLAUDE.md §51-59). Borradores no visibles,
 *     sin aviso. Si ya hay un lote en revisión para ese periodo, no crea otro.
 *  2. Solo si `nomina.quincenal.emision_automatica` está activa (por
 *     defecto NO), emite los borradores (de cualquier grupo) cuya fecha de
 *     pago ya llegó.
 * Ver docs/NOMINA_QUINCENAL.md.
 */
class ProcesarQuincenasNominaCommand extends Command
{
    protected $signature = 'nomina:procesar-quincenas {--fecha= : Fecha de referencia (por defecto hoy, hora de CDMX)}';

    protected $description = 'Prepara los lotes quincenal (corporativo) y semanal (sucursales) antes del pago, sin publicar';

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

            $this->prepararSiAplica($lotes, PeriodicidadNomina::Quincenal, $periodo, $responsable);
        }

        // Grupo semanal (sucursales): mismo mecanismo de anticipación, con
        // la semana lunes→domingo en vez de la quincena.
        foreach ([$lotes->periodo(PeriodicidadNomina::Semanal, $hoy), $lotes->periodo(PeriodicidadNomina::Semanal, $hoy->addWeek())] as $periodo) {
            if ($hoy->diffInDays($periodo['pago'], false) > $anticipacion) {
                continue;
            }

            $this->prepararSiAplica($lotes, PeriodicidadNomina::Semanal, $periodo, $responsable);
        }

        if (! config('nomina.quincenal.emision_automatica', false)) {
            $this->line('Emisión automática desactivada: RH revisa y emite el lote desde «Recibos de nómina».');

            return self::SUCCESS;
        }

        $resultado = $quincenas->emitirVencidos($hoy, $responsable);
        $this->info(sprintf('%d recibo(s) emitido(s) con fecha de pago al %s.', $resultado['emitidos'], $hoy->format('d/m/Y')));

        foreach ($resultado['fallidos'] as $fallido) {
            $this->warn(sprintf('%s quedó en borrador: %s', $fallido['folio'], $fallido['motivo']));
        }

        return self::SUCCESS;
    }

    /**
     * Prepara el lote del grupo/periodo indicado; si resulta vacío (nadie
     * de ese grupo de pago en este periodo — p. ej. todavía no hay
     * colaboradores corporativos o de sucursal) lo descarta de inmediato en
     * vez de dejar un lote fantasma con 0/0 estorbando en «Recibos de
     * nómina» cada corrida del cron.
     *
     * @param  array{inicio: CarbonInterface, fin: CarbonInterface, pago: CarbonInterface, etiqueta: string}  $periodo
     */
    private function prepararSiAplica(LoteNominaService $lotes, PeriodicidadNomina $periodicidad, array $periodo, User $responsable): void
    {
        try {
            $lote = $lotes->preparar($periodicidad, $periodo['inicio'], $responsable);
        } catch (ValidationException $e) {
            $this->line(sprintf('%s: %s', $periodo['etiqueta'], collect($e->errors())->flatten()->implode(' ')));

            return;
        }

        if ($lote->esperados === 0) {
            $lote->recibos()->delete();
            $lote->delete();

            return;
        }

        $this->info(sprintf('%s: lote %s preparado (%d de %d, %d error(es)). Pendiente de revisión y emisión por RH.', $periodo['etiqueta'], $lote->folio, $lote->preparados, $lote->esperados, count($lote->errores ?? [])));
    }
}
