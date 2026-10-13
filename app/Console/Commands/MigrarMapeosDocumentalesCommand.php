<?php

namespace App\Console\Commands;

use App\Services\DocumentosMaestros\MigracionMapeosService;
use Illuminate\Console\Command;

/**
 * php artisan people:migrar-mapeos-documentales [--simular] [--sin-verificar]
 *
 * Pasa el mapa de campos de cada versión de documento maestro del registro
 * heredado (config/documentos_maestros.php) a base de datos y verifica que
 * el mapa compilado desde BD sea IDÉNTICO al vigente. Idempotente. Ver
 * docs/MOTOR_DOCUMENTOS_MAESTROS.md («Motor no-code»).
 */
class MigrarMapeosDocumentalesCommand extends Command
{
    protected $signature = 'people:migrar-mapeos-documentales
                            {--simular : Solo reporta qué se migraría; no guarda nada}
                            {--sin-verificar : No vuelve a preparar los originales para comprobar la equivalencia}';

    protected $description = 'Migra el mapa de campos de los documentos maestros de config a base de datos (idempotente) y verifica la equivalencia.';

    public function handle(MigracionMapeosService $migracion): int
    {
        $reporte = $migracion->migrar((bool) $this->option('simular'), ! $this->option('sin-verificar'));

        $this->table(
            ['ID', 'Familia', 'Versión', 'Acción', 'Espacios', 'Equivalencia', 'Detalle'],
            array_map(fn (array $r): array => [$r['id'], $r['familia'], 'v'.$r['version'], $r['accion'], $r['campos'], $r['equivalencia'], $r['detalle'] ?? ''], $reporte),
        );

        $diferentes = array_filter($reporte, fn (array $r): bool => in_array($r['equivalencia'], ['diferente', 'error'], true));

        if ($diferentes !== []) {
            $this->error(sprintf('%d versión(es) NO equivalentes: revisa antes de editar su mapa en el editor (el mapa vigente no se modificó).', count($diferentes)));

            return self::FAILURE;
        }

        $this->info(sprintf('%d versión(es) revisadas.', count($reporte)));

        return self::SUCCESS;
    }
}
