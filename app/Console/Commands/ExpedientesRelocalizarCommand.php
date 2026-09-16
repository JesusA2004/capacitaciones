<?php

namespace App\Console\Commands;

use App\Models\Colaborador;
use App\Services\Expedientes\ExpedienteRelocationService;
use Illuminate\Console\Command;

/**
 * Dispara EXPLÍCITAMENTE la relocalización del expediente de un colaborador
 * a la carpeta que le corresponde con su empresa/sucursal/nombre actuales
 * (ver App\Services\Expedientes\ExpedienteRelocationService). Nunca corre
 * automático: un cambio de sucursal/nombre por sí solo deja el expediente
 * intacto en su carpeta histórica — alguien tiene que pedir esto a propósito.
 *
 * php artisan expedientes:relocalizar {colaborador}
 */
class ExpedientesRelocalizarCommand extends Command
{
    protected $signature = 'expedientes:relocalizar {colaborador : ID del colaborador (colaboradores.id)}';

    protected $description = 'Mueve el expediente completo de un colaborador a la carpeta del NAS que le corresponde con sus datos actuales';

    public function handle(ExpedienteRelocationService $servicio): int
    {
        $colaborador = Colaborador::withTrashed()->where('id', $this->argument('colaborador'))->first();

        if ($colaborador === null) {
            $this->error('No existe ningún colaborador con ese ID.');

            return self::FAILURE;
        }

        $resultado = $servicio->relocalizar($colaborador);

        $this->line("Ruta anterior: {$resultado['ruta_anterior']}");
        $this->line("Ruta nueva:    {$resultado['ruta_nueva']}");
        $this->line("Archivos:      {$resultado['archivos']}");
        $this->newLine();

        if ($resultado['movido']) {
            $this->info($resultado['detalle']);

            return self::SUCCESS;
        }

        $this->warn($resultado['detalle']);

        return self::FAILURE;
    }
}
