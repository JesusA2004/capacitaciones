<?php

namespace App\Console\Commands;

use App\Services\Puestos\Descriptivos\DescriptivoPuestoService;
use App\Services\Puestos\Descriptivos\LectorDescriptivoPdf;
use Illuminate\Console\Command;

/**
 * php artisan people:importar-descriptivos [--ruta=] [--simular]
 *
 * Lee los PDF oficiales de descriptivos (capa de texto, sin OCR ni
 * servicios externos), los guarda intactos y los asocia a su puesto por
 * nombre exacto o alias explícito (config/descriptivos.php). Idempotente:
 * el mismo PDF no se duplica. Los que no tienen puesto claro quedan para
 * que RH los confirme en Descriptivos y perfiles › Importar.
 */
class ImportarDescriptivosCommand extends Command
{
    protected $signature = 'people:importar-descriptivos
                            {--ruta= : Carpeta con los PDF (por defecto config descriptivos.carpeta)}
                            {--simular : Solo lee y reporta; no guarda nada}';

    protected $description = 'Importa los descriptivos de puesto oficiales (PDF) y los asocia a sus puestos.';

    public function handle(DescriptivoPuestoService $servicio): int
    {
        if (! app(LectorDescriptivoPdf::class)->disponible()) {
            $this->error('No se encontró Poppler (pdftotext). Instálalo para leer los PDF.');

            return self::FAILURE;
        }

        $reporte = $servicio->importarCarpeta($this->option('ruta') ?: null, null, (bool) $this->option('simular'));

        $this->table(
            ['Archivo', 'Puesto detectado', 'Puesto(s) PEOPLE', 'Págs.', 'Funciones', 'Secciones', 'Estado'],
            array_map(fn (array $f): array => [
                $f['archivo'], $f['puesto_detectado'] ?? '—', implode(', ', $f['puestos']) ?: '— RH confirma —',
                $f['paginas'], $f['funciones'], count($f['secciones']), $f['estado'].(isset($f['version']) ? ' v'.$f['version'] : ''),
            ], $reporte),
        );

        foreach ($reporte as $f) {
            foreach ($f['advertencias'] ?? [] as $advertencia) {
                $this->warn('⚠ '.$advertencia);
            }
        }

        return self::SUCCESS;
    }
}
