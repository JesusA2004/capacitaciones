<?php

namespace App\Console\Commands;

use App\Enums\EstadoReciboNomina;
use App\Models\ReciboNomina;
use App\Services\Nomina\ReciboNominaService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * php artisan nomina:reparar-recibos-pdf [--reparar]
 *
 * Revisa en bloques TODOS los recibos emitidos y reporta los que no tienen
 * su PDF físico (referencia vacía o archivo inexistente). Con --reparar los
 * recupera con ReciboNominaService::regenerarPdf() (referencia a un
 * documento generado que sí existe, o regeneración con el snapshot del
 * recibo y su diseño histórico). Nunca recalcula montos. Sin --reparar solo
 * reporta (seguro de correr en producción).
 */
class RepararRecibosPdfCommand extends Command
{
    protected $signature = 'nomina:reparar-recibos-pdf {--reparar : Recupera/regenera los PDF faltantes}';

    protected $description = 'Detecta (y opcionalmente repara) recibos de nómina emitidos sin PDF físico';

    public function handle(ReciboNominaService $recibos): int
    {
        $faltantes = 0;
        $reparados = 0;
        $fallidos = 0;

        ReciboNomina::query()
            ->where('estado', EstadoReciboNomina::Emitido->value)
            ->orderBy('id')
            ->chunkById(200, function ($lote) use ($recibos, &$faltantes, &$reparados, &$fallidos): void {
                foreach ($lote as $recibo) {
                    if ($recibos->pdfDisponible($recibo, false)) {
                        continue;
                    }

                    $faltantes++;

                    if (! $this->option('reparar')) {
                        $this->line(sprintf('Falta PDF: %s (recibo #%d)', $recibo->folio, $recibo->id));

                        continue;
                    }

                    try {
                        $recibos->regenerarPdf($recibo);
                        $reparados++;
                        $this->info(sprintf('Reparado: %s', $recibo->folio));
                    } catch (ValidationException $e) {
                        $fallidos++;
                        $this->warn(sprintf('No se pudo reparar %s: %s', $recibo->folio, (string) collect($e->errors())->flatten()->first()));
                    }
                }
            });

        $this->newLine();
        $this->info(sprintf('Recibos emitidos sin PDF: %d · reparados: %d · sin reparar: %d', $faltantes, $reparados, $fallidos));

        return $fallidos > 0 ? self::FAILURE : self::SUCCESS;
    }
}
