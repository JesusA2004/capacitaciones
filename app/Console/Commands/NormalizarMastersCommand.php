<?php

namespace App\Console\Commands;

use App\Models\DocumentTemplate;
use App\Services\DocumentosMaestros\AlmacenMaestrosService;
use App\Services\DocumentosMaestros\Calidad\CoberturaZonasService;
use App\Services\DocumentosMaestros\ImportadorFormatosJuridicosService;
use Illuminate\Console\Command;
use Throwable;

/**
 * php artisan documentos:normalizar-masters [--familia=] [--simular]
 *
 * Vuelve a preparar las versiones ACTIVAS de documentos maestros desde su
 * ORIGINAL guardado (no lee docs/formatos_fuente: sirve igual en el VPS)
 * con el mapa vigente de la BD: retira los marcatextos de Jurídico de la
 * copia normalizada (`limpiar_marcas`), aplica las reglas de cobertura y
 * repite el QA visual. El original nunca se modifica. Idempotente.
 */
class NormalizarMastersCommand extends Command
{
    protected $signature = 'documentos:normalizar-masters
                            {--familia= : Solo esta familia (p. ej. contrato_indeterminado.gestor)}
                            {--simular : Solo reporta lo que cambiaría}';

    protected $description = 'Re-prepara los documentos maestros activos desde su original (limpia marcatextos y repite el QA visual).';

    public function handle(ImportadorFormatosJuridicosService $importador, AlmacenMaestrosService $almacen, CoberturaZonasService $cobertura): int
    {
        $consulta = DocumentTemplate::query()->whereNotNull('familia')->where('activo', true)->orderBy('familia');

        if ($this->option('familia')) {
            $consulta->where('familia', (string) $this->option('familia'));
        }

        $filas = [];
        $fallos = 0;

        foreach ($consulta->get() as $master) {
            if ($this->option('simular')) {
                $c = $cobertura->de($master);
                $filas[] = [$master->familia, 'v'.$master->version, $c['zonas'], $c['sin_clasificar'], $c['marcas']['detectadas'], $c['marcas']['retiradas'], '(simulación)'];

                continue;
            }

            try {
                $resultado = $importador->registrarVersion(
                    (string) $master->familia,
                    $master->version,
                    $almacen->original($master),
                    [(string) ($master->original_nombre ?? $master->original_name ?? 'original')],
                    ['familia' => (string) $master->familia, 'version' => $master->version, 'activa' => true, 'bloqueada' => $master->estado_master === 'bloqueado', 'nota' => null],
                    null,
                );
                $c = $cobertura->de($resultado);
                $estado = $resultado->visual_validation_status->value;
                $fallos += $resultado->activo && $resultado->disenoValidado() ? 0 : 1;
                $filas[] = [$resultado->familia, 'v'.$resultado->version, $c['zonas'], $c['sin_clasificar'], $c['marcas']['detectadas'], $c['marcas']['retiradas'], sprintf('%s · %s', $estado, $resultado->activo ? 'activa' : 'INACTIVA')];
            } catch (Throwable $e) {
                $fallos++;
                $filas[] = [$master->familia, 'v'.$master->version, '', '', '', '', 'ERROR: '.$e->getMessage()];
            }
        }

        $this->table(['Familia', 'Versión', 'Zonas', 'Sin clasificar', 'Resaltados', 'Retirados', 'Diseño'], $filas);
        $this->line(sprintf('%d versión(es) revisada(s), %d con atención pendiente.', count($filas), $fallos));

        return $fallos > 0 ? self::FAILURE : self::SUCCESS;
    }
}
