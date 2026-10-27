<?php

namespace App\Console\Commands;

use App\Services\Expedientes\DocumentoParService;
use Illuminate\Console\Command;

/**
 * Reintento de la separación que hace la migración
 * 2026_10_27_090000_separar_cartas_de_recomendacion: copia a la «Carta de
 * recomendación 2» lo que se cargó cuando las dos cartas se pedían en un
 * solo archivo. Idempotente (quien ya tiene carta 2 se omite); cada archivo
 * que no se pudo copiar se lista.
 */
class ExpedientesSepararCartasRecomendacionCommand extends Command
{
    protected $signature = 'expedientes:separar-cartas-recomendacion';

    protected $description = 'Copia a la carta de recomendación 2 los archivos cargados cuando las dos cartas venían en uno solo';

    public function handle(DocumentoParService $pares): int
    {
        $resultado = $pares->separarArchivosCompartidos(
            'carta_recomendacion_1',
            'carta_recomendacion_2',
            'Copia de la carta 1: cuando se cargó, las dos cartas se pedían en un solo archivo.',
        );

        $this->line(sprintf('  <fg=green>✓</> %d copiado(s) a la carta 2 · %d ya tenían carta 2.', $resultado['copiados'], $resultado['omitidos']));

        foreach ($resultado['errores'] as $error) {
            $this->line(sprintf('  <fg=red>✗</> %s', $error));
        }

        return $resultado['errores'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
