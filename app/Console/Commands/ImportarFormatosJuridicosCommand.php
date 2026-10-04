<?php

namespace App\Console\Commands;

use App\Services\DocumentosMaestros\ImportadorFormatosJuridicosService;
use Illuminate\Console\Command;
use Throwable;

/**
 * php artisan people:importar-formatos-juridicos [--ruta=] [--simular]
 *
 * Incorpora los formatos ORIGINALES de Jurídico/RH (DOCX/PDF) como
 * documentos maestros: SHA-256 → familia/versión → original inmutable →
 * master técnico preparado por el sistema → reporte de campos. Idempotente.
 * Ver docs/MOTOR_DOCUMENTOS_MAESTROS.md.
 */
class ImportarFormatosJuridicosCommand extends Command
{
    protected $signature = 'people:importar-formatos-juridicos
                            {--ruta= : Carpeta con los originales (por defecto config documentos_maestros.carpeta_fuente)}
                            {--simular : Solo inventaría y reporta; no guarda nada}';

    protected $description = 'Importa los formatos jurídicos originales como documentos maestros (idempotente).';

    public function handle(ImportadorFormatosJuridicosService $importador): int
    {
        $ruta = (string) ($this->option('ruta') ?: config('documentos_maestros.carpeta_fuente'));
        $this->info("Carpeta: {$ruta}");

        try {
            $resultado = $importador->importarCarpeta($ruta, null, (bool) $this->option('simular'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Archivo', 'Tipo', 'SHA-256', 'Master(s)', 'Duplicado de'],
            array_map(fn (array $a): array => [$a['archivo'], $a['tipo'], substr($a['sha256'], 0, 12).'…', implode(', ', $a['familias']) ?: '— desconocido —', $a['duplicado_de'] ?? ''], $resultado['archivos']),
        );

        if ($resultado['versiones'] !== []) {
            $this->table(
                ['Familia', 'Versión', 'Estado', 'Activo', 'Detectados', 'Mapeados', 'Pendientes'],
                array_map(fn (array $v): array => [
                    $v['familia'], $v['version'], $v['estado'] ?? $v['accion'] ?? '', isset($v['activo']) ? ($v['activo'] ? 'sí' : 'no') : '',
                    $v['detectados'] ?? '', $v['mapeados'] ?? '', $v['pendientes'] ?? '',
                ], $resultado['versiones']),
            );
        }

        if ($resultado['anomalias'] !== []) {
            $this->warn('Anomalías / pendientes:');

            foreach ($resultado['anomalias'] as $anomalia) {
                $this->line(' - '.$anomalia);
            }
        }

        $this->info(sprintf('%d archivo(s), %d versión(es) de master procesadas.', count($resultado['archivos']), count($resultado['versiones'])));

        return self::SUCCESS;
    }
}
