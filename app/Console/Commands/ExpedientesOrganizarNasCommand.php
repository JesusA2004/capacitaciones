<?php

namespace App\Console\Commands;

use App\Services\Expedientes\ExpedienteNasOrganizacionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Reorganiza los archivos legacy del NAS (`expedientes/{id}/{uuid}.ext`) a
 * la estructura legible actual (ver docs/ESTRUCTURA_EXPEDIENTES_NAS.md).
 * Por defecto es un DRY RUN: no toca disco ni BD, solo imprime el plan.
 *
 * php artisan expedientes:organizar-nas                    → dry run
 * php artisan expedientes:organizar-nas --apply --confirm  → aplica de verdad
 * php artisan expedientes:organizar-nas --rollback=<ruta>  → revierte un manifiesto
 *
 * Nunca se debe ejecutar --apply en producción sin que un humano haya visto
 * antes el plan del dry run.
 */
class ExpedientesOrganizarNasCommand extends Command
{
    protected $signature = 'expedientes:organizar-nas
        {--apply : Aplica los cambios (por defecto solo se simula)}
        {--confirm : Confirma --apply sin preguntar interactivamente (para uso no interactivo)}
        {--prune-empty-legacy : Junto con --apply, borra las carpetas legacy numéricas que queden completamente vacías tras aplicar}
        {--rollback= : Ruta a un manifiesto JSON previo; revierte sus filas con resultado=ok}';

    protected $description = 'Migra los documentos de expediente en el NAS de rutas legacy (UUID) a la estructura legible por empresa/sucursal/colaborador';

    public function handle(ExpedienteNasOrganizacionService $servicio): int
    {
        if ($rutaManifiesto = $this->option('rollback')) {
            return $this->ejecutarRollback($servicio, $rutaManifiesto);
        }

        $aplicar = (bool) $this->option('apply');

        if ($aplicar && ! $this->confirmarAplicacion()) {
            $this->warn('Cancelado: no se aplicó ningún cambio.');

            return self::FAILURE;
        }

        $lock = Cache::lock('expedientes:organizar-nas', 3600);

        if (! $lock->get()) {
            $this->error('Ya hay una ejecución de expedientes:organizar-nas en curso. Espera a que termine.');

            return self::FAILURE;
        }

        try {
            return $this->ejecutarPlan($servicio, $aplicar);
        } finally {
            $lock->release();
        }
    }

    private function confirmarAplicacion(): bool
    {
        if ($this->option('confirm')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->error('--apply requiere --confirm cuando se ejecuta de forma no interactiva.');

            return false;
        }

        return $this->confirm('Esto va a MOVER archivos reales en el NAS y actualizar la base de datos. ¿Continuar?');
    }

    private function ejecutarPlan(ExpedienteNasOrganizacionService $servicio, bool $aplicar): int
    {
        $this->info($aplicar ? 'Aplicando reorganización del NAS...' : 'Simulando reorganización del NAS (dry run, no se modifica nada)...');

        $plan = $servicio->planificar();
        $manifiesto = $servicio->ejecutar($plan, $aplicar);

        $conteos = $manifiesto->countBy('resultado');

        foreach ($manifiesto as $item) {
            $this->imprimirItem($item);
        }

        $this->newLine();
        $this->line('<fg=blue>Resumen</>');
        foreach ($conteos as $resultado => $cantidad) {
            $this->line("  {$resultado}: {$cantidad}");
        }

        if ($aplicar) {
            $rutaManifiesto = $servicio->escribirManifiesto($manifiesto);
            $this->newLine();
            $this->info("Manifiesto guardado en: {$rutaManifiesto}");
            $this->line('Para revertir: php artisan expedientes:organizar-nas --rollback='.$rutaManifiesto);
        }

        $this->reportarHuerfanosYVacias($servicio);

        $huboProblemas = ($conteos['conflicto'] ?? 0) > 0
            || ($conteos['error_copia'] ?? 0) > 0
            || ($conteos['error_bd'] ?? 0) > 0
            || ($conteos['error_verificacion_final'] ?? 0) > 0
            || ($conteos['error_borrado_origen'] ?? 0) > 0;

        if ($aplicar && ! $huboProblemas && $this->option('prune-empty-legacy')) {
            $this->podarCarpetasLegaciesVacias($servicio);
        }

        return $huboProblemas ? self::FAILURE : self::SUCCESS;
    }

    private function podarCarpetasLegaciesVacias(ExpedienteNasOrganizacionService $servicio): void
    {
        $this->newLine();
        $this->line('<fg=blue>Podando carpetas legacy numéricas vacías...</>');

        $podadas = $servicio->podarCarpetasLegaciesVacias();

        if ($podadas === []) {
            $this->line('  Ninguna carpeta para podar.');

            return;
        }

        foreach ($podadas as $carpeta) {
            $this->line("  <fg=green>borrada</> {$carpeta}");
        }

        $this->info(count($podadas).' carpeta(s) legacy vacía(s) borrada(s).');
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function imprimirItem(array $item): void
    {
        $resultado = $item['resultado'];
        $etiqueta = match ($resultado) {
            'ok', 'sin_cambio', 'duplicado_resuelto' => "<fg=green>{$resultado}</>",
            'pendiente_de_aplicar' => '<fg=yellow>MOVER</>',
            'faltante', 'conflicto', 'error_copia', 'error_bd', 'error_verificacion_final', 'error_borrado_origen', 'sin_colaborador_o_tipo' => "<fg=red>{$resultado}</>",
            default => $resultado,
        };

        if (in_array($resultado, ['sin_cambio'], true)) {
            return;
        }

        $referencia = $item['tipo'] === 'documento'
            ? "employee_document_id={$item['employee_document_id']}"
            : "foto de user_id={$item['user_id']}";

        $this->line("[{$etiqueta}] {$referencia}");

        if (in_array($resultado, ['pendiente_de_aplicar', 'ok', 'duplicado_resuelto'], true) && $item['new_path'] !== null) {
            $this->line("    de: {$item['old_path']}");
            $this->line("    a:  {$item['new_path']}");
        }

        if (! empty($item['detalle'])) {
            $this->line("    → {$item['detalle']}");
        }
    }

    private function reportarHuerfanosYVacias(ExpedienteNasOrganizacionService $servicio): void
    {
        $huerfanos = $servicio->huerfanos();

        $this->newLine();
        $this->line('<fg=blue>Archivos huérfanos en el NAS (sin fila en BD — nunca se borran automáticamente)</>');

        if ($huerfanos === []) {
            $this->line('  Ninguno.');
        } else {
            foreach ($huerfanos as $ruta) {
                $this->line("  - {$ruta}");
            }
        }

        $vacias = $servicio->carpetasLegacyVacias();

        $this->newLine();
        $this->line('<fg=blue>Carpetas legacy numéricas completamente vacías</>');

        if ($vacias === []) {
            $this->line('  Ninguna.');

            return;
        }

        foreach ($vacias as $carpeta) {
            $this->line("  - {$carpeta}");
        }
    }

    private function ejecutarRollback(ExpedienteNasOrganizacionService $servicio, string $rutaManifiesto): int
    {
        $this->warn("Revirtiendo manifiesto: {$rutaManifiesto}");

        $resultados = $servicio->rollback($rutaManifiesto);

        $errores = 0;

        foreach ($resultados as $fila) {
            if ($fila['rollback'] === 'ok') {
                $this->line('<fg=green>ok</> '.($fila['tipo'] === 'documento' ? "employee_document_id={$fila['employee_document_id']}" : "foto de user_id={$fila['user_id']}"));

                continue;
            }

            $errores++;
            $this->line("<fg=red>error</> {$fila['new_path']} → {$fila['rollback_detalle']}");
        }

        $this->newLine();
        $this->info(count($resultados)." fila(s) revertida(s) intentadas, {$errores} error(es).");

        return $errores > 0 ? self::FAILURE : self::SUCCESS;
    }
}
