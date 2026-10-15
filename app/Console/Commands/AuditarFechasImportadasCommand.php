<?php

namespace App\Console\Commands;

use App\Services\ImportadorTrabajadores\AuditoriaFechasImportadasService;
use Illuminate\Console\Command;

/**
 * Lista las fechas de ingreso que el importador de trabajadores sobrescribió
 * con la regla vieja y dictamina RESTAURAR / REVISIÓN MANUAL. Por omisión
 * solo informa; --restaurar aplica únicamente los casos sin ambigüedad.
 */
class AuditarFechasImportadasCommand extends Command
{
    protected $signature = 'people:auditar-fechas-importadas {--restaurar : Restaura solo los casos sin ambigüedad (dictamen RESTAURAR)}';

    protected $description = 'Audita las fechas de ingreso que el importador de Excel llegó a modificar';

    public function handle(AuditoriaFechasImportadasService $servicio): int
    {
        $casos = $servicio->diagnosticar();

        if ($casos === []) {
            $this->info('Ninguna importación aplicada modificó fechas de ingreso.');

            return self::SUCCESS;
        }

        $etiquetas = [
            AuditoriaFechasImportadasService::RESTAURAR => 'RESTAURAR',
            AuditoriaFechasImportadasService::REVISION_MANUAL => 'REVISIÓN MANUAL',
            AuditoriaFechasImportadasService::YA_CORREGIDA => 'YA CORREGIDA',
        ];

        $this->table(
            ['Importación', 'Fila', 'Colaborador', 'Fecha anterior', 'Fecha que puso la importación', 'Fecha actual', 'Dictamen', 'Motivo'],
            array_map(fn (array $c): array => [
                sprintf('#%d %s', $c['importacion_id'], $c['archivo']),
                $c['fila'],
                sprintf('#%d %s', $c['colaborador_id'], $c['colaborador']),
                $c['fecha_anterior'] ?? '—',
                $c['fecha_importada'],
                $c['fecha_actual'] ?? '—',
                $etiquetas[$c['dictamen']] ?? $c['dictamen'],
                $c['motivo'],
            ], $casos),
        );

        $porRestaurar = count(array_filter($casos, fn (array $c): bool => $c['dictamen'] === AuditoriaFechasImportadasService::RESTAURAR));
        $manuales = count(array_filter($casos, fn (array $c): bool => $c['dictamen'] === AuditoriaFechasImportadasService::REVISION_MANUAL));

        $this->line(sprintf('Restaurables sin ambigüedad: %d · Revisión manual: %d', $porRestaurar, $manuales));

        if (! $this->option('restaurar')) {
            if ($porRestaurar > 0) {
                $this->comment('Para restaurarlas: php artisan people:auditar-fechas-importadas --restaurar');
            }

            return self::SUCCESS;
        }

        $restaurados = $servicio->restaurar($casos, null);
        $this->info(sprintf('Fechas restauradas: %d. Las de REVISIÓN MANUAL no se tocaron.', $restaurados));

        return self::SUCCESS;
    }
}
