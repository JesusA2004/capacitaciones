<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deja la base de datos COMPLETAMENTE limpia para cargar datos reales de
 * producción: borra todas las tablas y vuelve a correr las migraciones
 * (migrate:fresh). Con --seed, además siembra los catálogos y roles/permisos
 * base (producción-safe, SIN personas de demo — ver DatabaseSeeder).
 *
 * NO toca los archivos del NAS/almacenamiento (PDFs de expedientes): esos
 * viven en disco; este comando solo vacía la base de datos. Si vas a empezar
 * de cero también los archivos, hazlo aparte y con cuidado.
 *
 * Es DESTRUCTIVO e irreversible. Pide confirmación escrita salvo --force.
 */
class ResetProduccionCommand extends Command
{
    protected $signature = 'people:reset-produccion
        {--seed : Sembrar catálogos y roles/permisos base tras el reset}
        {--force : Omitir la confirmación escrita (úsalo solo en automatización)}';

    protected $description = 'Borra TODA la base de datos y reaplica migraciones para arrancar con datos reales.';

    private const FRASE = 'BORRAR TODO';

    public function handle(): int
    {
        $conexion = DB::connection();
        $base = (string) $conexion->getDatabaseName();
        $entorno = (string) app()->environment();

        $this->newLine();
        $this->components->warn('Esto BORRA TODA la base de datos y es IRREVERSIBLE.');
        $this->components->twoColumnDetail('<fg=gray>Entorno</>', $entorno);
        $this->components->twoColumnDetail('<fg=gray>Conexión</>', (string) $conexion->getName());
        $this->components->twoColumnDetail('<fg=gray>Base de datos</>', "<options=bold>{$base}</>");
        $this->components->twoColumnDetail('<fg=gray>Se borrará</>', 'colaboradores, usuarios, expedientes, documentos, solicitudes, nómina, todo');
        $this->components->twoColumnDetail('<fg=gray>NO se toca</>', 'los archivos del NAS/almacenamiento (solo la base de datos)');
        $this->newLine();

        if (! $this->option('force')) {
            $respuesta = (string) $this->ask(sprintf('Para confirmar, escribe exactamente «%s»', self::FRASE));

            if (trim($respuesta) !== self::FRASE) {
                $this->components->warn('Texto incorrecto: no se borró nada.');

                return self::FAILURE;
            }
        }

        // Si un paso falla se detiene aquí (nunca se reporta «reiniciada» con
        // la base a medias): repite el comando tras corregir la causa.
        $migro = false;
        $this->components->task('Borrando y reaplicando migraciones (migrate:fresh)', function () use (&$migro): bool {
            $migro = $this->call('migrate:fresh', ['--force' => true]) === self::SUCCESS;

            return $migro;
        });

        if (! $migro) {
            $this->components->error('migrate:fresh falló: revisa el error de arriba. No se sembró nada.');

            return self::FAILURE;
        }

        if ($this->option('seed')) {
            $sembro = false;
            $this->components->task('Sembrando catálogos y roles/permisos base (db:seed)', function () use (&$sembro): bool {
                $sembro = $this->call('db:seed', ['--force' => true]) === self::SUCCESS;

                return $sembro;
            });

            if (! $sembro) {
                $this->components->error('db:seed falló: revisa el error de arriba y repite con --seed.');

                return self::FAILURE;
            }
        }

        $this->components->task('Limpiando cachés (config, route, cache, view)', function (): bool {
            $this->callSilent('config:clear');
            $this->callSilent('route:clear');
            $this->callSilent('cache:clear');
            $this->callSilent('view:clear');

            return true;
        });

        $this->newLine();
        $this->components->info('Base de datos reiniciada.');

        if (! $this->option('seed')) {
            $this->components->warn('No sembraste catálogos ni roles. Corre: php artisan db:seed --force (o repite con --seed) y luego php artisan people:sincronizar-permisos');
        } else {
            $this->line('  Siguiente paso: <options=bold>php artisan people:crear-admin</> para crear tu cuenta de acceso.');
        }

        return self::SUCCESS;
    }
}
