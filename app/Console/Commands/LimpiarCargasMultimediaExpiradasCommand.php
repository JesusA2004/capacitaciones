<?php

namespace App\Console\Commands;

use App\Services\Multimedia\CargaResumibleService;
use Illuminate\Console\Command;

/**
 * Política de retención de cargas de video por bloques abandonadas: una
 * carga que no se completó antes de `expira_en` se marca como expirada y se
 * borran sus bloques temporales del disco (docs/AUDITORIA_CUMPLIMIENTO.md
 * sección 7).
 */
class LimpiarCargasMultimediaExpiradasCommand extends Command
{
    protected $signature = 'capacitacion:limpiar-cargas-expiradas';

    protected $description = 'Marca como expiradas las cargas de video por bloques abandonadas y borra sus bloques temporales';

    public function handle(CargaResumibleService $service): int
    {
        $total = $service->limpiarExpiradas();

        $this->info("Cargas expiradas limpiadas: {$total}.");

        return self::SUCCESS;
    }
}
