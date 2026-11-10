<?php

namespace App\Console\Commands;

use App\Services\Notificaciones\AvisoAltaSistemasService;
use App\Services\Sistemas\PendientesAltaSistemasService;
use Illuminate\Console\Command;

/**
 * Regularización de la bandeja «Pendientes de alta en sistemas»: crea el
 * pendiente de quien ya llegó a contratación (o más adelante) sin aviso —
 * contratados antes de que existiera el aviso, altas digitales o directas.
 * Idempotente. Por defecto NO manda correos (para no reenviar en masa altas
 * viejas): solo con --enviar.
 *
 *   php artisan sistemas:avisos-alta-pendientes --dry-run
 *   php artisan sistemas:avisos-alta-pendientes --dias=30
 *   php artisan sistemas:avisos-alta-pendientes --dias=7 --enviar
 */
class RegularizarAvisosAltaSistemasCommand extends Command
{
    protected $signature = 'sistemas:avisos-alta-pendientes
        {--dias=60 : Solo personas con fecha de ingreso (o alta) en los últimos N días}
        {--dry-run : Solo muestra a quién le falta, sin crear nada}
        {--enviar : Además de crear el pendiente, manda el correo a Sistemas}';

    protected $description = 'Crea los pendientes de alta en sistemas que falten (contratados recientes sin aviso).';

    public function handle(PendientesAltaSistemasService $pendientes, AvisoAltaSistemasService $avisos): int
    {
        $dias = filter_var($this->option('dias'), FILTER_VALIDATE_INT);

        if (! is_int($dias) || $dias < 1) {
            $this->error('--dias debe ser un número entero mayor a 0.');

            return self::FAILURE;
        }

        $simulacion = (bool) $this->option('dry-run');
        $enviar = (bool) $this->option('enviar');

        if ($simulacion && $enviar) {
            $this->error('Usa --dry-run o --enviar, no ambos.');

            return self::FAILURE;
        }

        if ($enviar && ! $avisos->activo()) {
            $this->warn('El correo está desactivado (SISTEMAS_ALTA_CORREO_ACTIVO / SISTEMAS_ALTA_CORREO_PARA): se crean los pendientes sin correo.');
        }

        $filas = $pendientes->regularizar($dias, ! $simulacion, $enviar);

        if ($filas === []) {
            $this->info(sprintf('Nada que regularizar: todos los ingresos de los últimos %d días ya tienen su pendiente.', $dias));

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Colaborador', 'Origen', 'Ingreso'],
            array_map(fn (array $f): array => [$f['colaborador_id'], $f['nombre'], $f['origen'], $f['fecha_ingreso'] ?? '—'], $filas),
        );

        $this->info(match (true) {
            $simulacion => sprintf('Simulación: %d persona(s) sin pendiente. No se creó nada.', count($filas)),
            $enviar => sprintf('%d pendiente(s) creados; su correo a Sistemas quedó en cola.', count($filas)),
            default => sprintf('%d pendiente(s) creados sin correo (usa --enviar para mandarlo).', count($filas)),
        });

        return self::SUCCESS;
    }
}
