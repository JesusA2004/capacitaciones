<?php

namespace App\Console\Commands;

use App\Services\Permisos\SincronizadorPermisosService;
use Illuminate\Console\Command;

/**
 * Paso obligatorio de deploy, después de `migrate --force` (ver
 * docs/DEPLOY.md): crea los permisos nuevos del catálogo y agrega a los
 * roles base solo los que les falten, sin quitar personalizaciones. Ver
 * App\Services\Permisos\SincronizadorPermisosService.
 */
class SincronizarPermisosCommand extends Command
{
    protected $signature = 'people:sincronizar-permisos {--simular : Solo muestra qué cambiaría, sin escribir nada}';

    protected $description = 'Crea permisos nuevos del catálogo y otorga a los roles base los que les falten (idempotente, nunca quita permisos)';

    public function handle(SincronizadorPermisosService $sincronizador): int
    {
        $simular = (bool) $this->option('simular');
        $resultado = $sincronizador->sincronizar($simular);

        if ($simular) {
            $this->warn('Modo simulación: no se escribió nada.');
        }

        $this->line(sprintf('Permisos nuevos: %d', count($resultado['permisos_creados'])));
        foreach ($resultado['permisos_creados'] as $permiso) {
            $this->line("  + {$permiso}");
        }

        $this->line(sprintf('Roles nuevos: %d', count($resultado['roles_creados'])));
        foreach ($resultado['roles_creados'] as $rol) {
            $this->line("  + {$rol}");
        }

        $this->line(sprintf('Roles con permisos base agregados: %d', count($resultado['permisos_otorgados'])));
        foreach ($resultado['permisos_otorgados'] as $rol => $permisos) {
            $this->line(sprintf('  %s: %s', $rol, implode(', ', $permisos)));
        }

        $this->info($simular ? 'Simulación terminada.' : 'Permisos sincronizados. Caché de permisos limpiada.');

        return self::SUCCESS;
    }
}
