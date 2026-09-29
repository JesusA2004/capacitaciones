<?php

namespace App\Console\Commands;

use App\Services\Organigrama\SincronizadorOrganigramaService;
use Illuminate\Console\Command;

/**
 * Aplica en una base existente (producción incluida) la estructura
 * organizacional confirmada — ver SincronizadorOrganigramaService y
 * docs/ORGANIGRAMA.md. Seguro: nunca borra datos en uso ni asigna personas;
 * lo que no puede decidir solo lo reporta como conflicto.
 *
 *   php artisan people:sincronizar-organigrama --simular   # solo reporta
 *   php artisan people:sincronizar-organigrama             # aplica
 */
class SincronizarOrganigramaCommand extends Command
{
    protected $signature = 'people:sincronizar-organigrama {--simular : Solo muestra qué cambiaría, sin escribir nada}';

    protected $description = 'Sincroniza puestos, jerarquía, regiones Q1/Q3 y la clasificación de la matriz comercial con la estructura confirmada (idempotente, no borra datos en uso)';

    private const TITULOS = [
        'puestos_nuevos' => 'Puestos nuevos',
        'puestos_renombrados' => 'Puestos renombrados',
        'relaciones_actualizadas' => 'Relaciones jerárquicas actualizadas',
        'fuera_de_estructura' => 'Puestos fuera de la estructura confirmada (se conservan)',
        'regiones' => 'Regiones',
        'q2' => 'Q2',
        'rutas_clasificadas' => 'Entradas de la matriz clasificadas',
        'nodos_legacy' => 'Nodos legacy (cambian de tipo)',
        'conflictos' => 'Conflictos a revisar a mano',
    ];

    public function handle(SincronizadorOrganigramaService $sincronizador): int
    {
        $simular = (bool) $this->option('simular');

        if ($simular) {
            $this->warn('Modo simulación: no se escribe nada.');
        }

        $reporte = $sincronizador->sincronizar($simular);

        foreach (self::TITULOS as $clave => $titulo) {
            $lineas = $reporte[$clave] ?? [];
            $this->newLine();
            $this->line(sprintf('<fg=blue>%s</> (%d)', $titulo, count($lineas)));

            foreach ($lineas as $linea) {
                $this->line(sprintf('  %s %s', $clave === 'conflictos' ? '<fg=yellow>!</>' : '-', $linea));
            }
        }

        $this->newLine();
        $this->info($simular ? 'Simulación terminada.' : 'Organigrama sincronizado.');

        return self::SUCCESS;
    }
}
