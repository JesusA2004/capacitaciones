<?php

namespace App\Console\Commands;

use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Vacantes\VacanteAutoGenerationService;
use Illuminate\Console\Command;

/**
 * Recalcula TODAS las vacantes automáticas contra la regla única
 * (faltantes = max(autorizada − ocupada, 0), HeadcountService). Seguro en
 * producción: no toca vacantes manuales de RH, no borra nada (cierra o
 * cancela con motivo) y es idempotente.
 *
 *   php artisan people:sincronizar-vacantes --simular   # solo reporta
 *   php artisan people:sincronizar-vacantes             # aplica
 */
class SincronizarVacantesCommand extends Command
{
    protected $signature = 'people:sincronizar-vacantes {--simular : Solo muestra qué cambiaría, sin escribir nada}';

    protected $description = 'Abre, ajusta y cierra vacantes automáticas según plantilla autorizada − ocupados reales (idempotente, no toca vacantes manuales)';

    public function handle(VacanteAutoGenerationService $vacantes, AuditoriaService $auditoria): int
    {
        $simular = (bool) $this->option('simular');

        if ($simular) {
            $this->warn('Modo simulación: no se escribe nada.');
        }

        $reporte = $vacantes->sincronizarTodo($simular);
        $sucursales = Sucursal::query()->pluck('nombre', 'id');
        $puestos = Puesto::query()->pluck('nombre', 'id');

        $this->table(
            ['Acción', 'Sucursal', 'Puesto', 'Autorizadas', 'Ocupadas', 'Plazas antes', 'Plazas después', 'Vacante'],
            array_map(fn (array $c) => [
                match ($c['accion']) {
                    'abierta' => 'Abre',
                    'cerrada' => 'Cierra',
                    default => 'Ajusta',
                },
                $sucursales[$c['sucursal_id']] ?? $c['sucursal_id'],
                $puestos[$c['puesto_id']] ?? $c['puesto_id'],
                $c['autorizada'],
                $c['ocupada'],
                $c['plazas_antes'] ?? '—',
                $c['plazas_despues'] ?? '—',
                $c['vacante_id'] !== null ? '#'.$c['vacante_id'] : ($simular ? '(nueva)' : '—'),
            ], $reporte['cambios']),
        );

        $this->info(sprintf(
            '%s: %d abiertas, %d cerradas, %d ajustadas, %d sin cambios.',
            $simular ? 'Simulación' : 'Vacantes sincronizadas',
            $reporte['abiertas'],
            $reporte['cerradas'],
            $reporte['actualizadas'],
            $reporte['sin_cambios'],
        ));

        if (! $simular) {
            $auditoria->registrar('vacantes_sincronizadas', null, null, [
                'abiertas' => $reporte['abiertas'],
                'cerradas' => $reporte['cerradas'],
                'actualizadas' => $reporte['actualizadas'],
                'cambios' => $reporte['cambios'],
            ]);
        }

        return self::SUCCESS;
    }
}
