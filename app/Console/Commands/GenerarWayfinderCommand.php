<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Genera los helpers de Wayfinder sin depender de la base de datos.
 *
 * Wayfinder deduce el tipo de cada parámetro de ruta leyendo la columna en la
 * base o, si no se puede conectar, el docblock del modelo. Así, el mismo código
 * daba tipos distintos según el entorno y `vue-tsc` fallaba en un clon limpio
 * (`{rol}` salía `string`). Aquí siempre se resuelve contra un SQLite vacío en
 * memoria: el resultado es el mismo con o sin base, migrada o no.
 */
class GenerarWayfinderCommand extends Command
{
    protected $signature = 'people:wayfinder {--path=} {--skip-actions} {--skip-routes} {--with-form}';

    protected $description = 'Genera los helpers de Wayfinder con el mismo resultado en cualquier entorno, sin consultar la base de datos.';

    public function handle(): int
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.url' => null,
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');

        return $this->call('wayfinder:generate', array_filter([
            '--path' => $this->option('path'),
            '--skip-actions' => $this->option('skip-actions'),
            '--skip-routes' => $this->option('skip-routes'),
            '--with-form' => $this->option('with-form'),
        ]));
    }
}
