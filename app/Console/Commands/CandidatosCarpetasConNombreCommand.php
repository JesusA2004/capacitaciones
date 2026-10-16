<?php

namespace App\Console\Commands;

use App\Models\Candidato;
use App\Services\Reclutamiento\ExpedienteCandidatoService;
use Illuminate\Console\Command;

/**
 * Pasa los mini expedientes de candidatos que quedaron en la carpeta antigua
 * por número (`candidatos/{id}/...`) a su carpeta con nombre
 * (`candidatos/{Nombre Apellidos} - {id}/...`) en el NAS. Idempotente.
 *
 * php artisan candidatos:carpetas-con-nombre --simular
 */
class CandidatosCarpetasConNombreCommand extends Command
{
    protected $signature = 'candidatos:carpetas-con-nombre {--simular : Solo muestra qué se movería, sin tocar el NAS ni la BD}';

    protected $description = 'Mueve los archivos de cada candidato a su carpeta del NAS con su nombre';

    public function handle(ExpedienteCandidatoService $servicio): int
    {
        $simular = (bool) $this->option('simular');
        $total = 0;
        $faltantes = 0;
        $fallidos = 0;

        Candidato::query()->orderBy('id')->each(function (Candidato $candidato) use ($servicio, $simular, &$total, &$faltantes, &$fallidos): void {
            $resultado = $servicio->moverACarpetaConNombre($candidato, $simular);

            if ($resultado['movidos'] > 0) {
                $this->line(sprintf('#%d → %s (%d archivo(s))', $candidato->id, $resultado['carpeta'], $resultado['movidos']));
            }

            foreach ($resultado['faltantes'] as $ruta) {
                $this->warn(sprintf('#%d: no existe en el NAS: %s', $candidato->id, $ruta));
            }

            foreach ($resultado['fallidos'] as $ruta) {
                $this->error(sprintf('#%d: no se pudo mover (la BD quedó intacta): %s', $candidato->id, $ruta));
            }

            $total += $resultado['movidos'];
            $faltantes += count($resultado['faltantes']);
            $fallidos += count($resultado['fallidos']);
        });

        $this->newLine();
        $this->info(sprintf('%s %d archivo(s). Faltantes en el NAS: %d. Fallidos al mover: %d.', $simular ? 'Se moverían' : 'Movidos', $total, $faltantes, $fallidos));

        return self::SUCCESS;
    }
}
