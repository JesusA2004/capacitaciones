<?php

namespace App\Console\Commands;

use App\Services\Permisos\SincronizadorPermisosService;
use Illuminate\Console\Command;

/**
 * Paso obligatorio de deploy, después de `migrate --force` (ver
 * docs/DEPLOY.md): crea los permisos nuevos del catálogo y otorga sus
 * defaults SOLO de esos permisos nuevos (más la herencia del permiso
 * general del que se separaron); nunca quita ni re-otorga lo que un
 * administrador cambió en «Roles y permisos». Ver
 * App\Services\Permisos\SincronizadorPermisosService.
 */
class SincronizarPermisosCommand extends Command
{
    protected $signature = 'people:sincronizar-permisos {--simular : Solo muestra qué cambiaría, sin escribir nada}';

    protected $description = 'Crea permisos nuevos del catálogo y otorga sus defaults solo de los permisos nuevos (idempotente, nunca quita ni re-otorga permisos)';

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

        $this->line(sprintf('Permisos nuevos heredados de uno general: %d', count($resultado['heredados'])));
        foreach ($resultado['heredados'] as $permiso => $destinatarios) {
            $this->line(sprintf('  %s → %s', $permiso, implode(', ', $destinatarios)));
        }

        $this->info($simular ? 'Simulación terminada.' : 'Permisos sincronizados. Caché de permisos limpiada.');

        return self::SUCCESS;
    }
}
