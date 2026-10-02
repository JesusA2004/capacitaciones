<?php

namespace App\Console\Commands;

use App\Services\Organigrama\JefeDirectoService;
use Illuminate\Console\Command;

/**
 * Recalcula el jefe directo de todas las personas vigentes a partir del
 * organigrama (ver JefeDirectoService). Idempotente: solo escribe y audita
 * lo que cambió.
 */
class SincronizarJefesDirectosCommand extends Command
{
    protected $signature = 'organigrama:sincronizar-jefes';

    protected $description = 'Recalcula el jefe directo de cada persona a partir del organigrama';

    public function handle(JefeDirectoService $jefes): int
    {
        $resultado = $jefes->sincronizar();

        $this->info(sprintf('Jefes directos sincronizados: %d actualizados.', $resultado['actualizados']));
        $this->line(sprintf('Personas sin jefe (encabezan la estructura): %d.', $resultado['sin_jefe']));

        return self::SUCCESS;
    }
}
