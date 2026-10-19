<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\WipeCommand;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;

/**
 * Deja la base de datos de PEOPLE COMPLETAMENTE limpia para cargar datos
 * reales de producción: borra todas las tablas de la conexión de PEOPLE y
 * vuelve a correr las migraciones (migrate:fresh). NO siembra nada: el
 * siguiente paso es `php artisan people:bootstrap-produccion`.
 *
 * Es el ÚNICO reinicio destructivo autorizado en producción. AppServiceProvider
 * prohíbe los comandos destructivos (`DB::prohibitDestructiveCommands()`) cuando
 * APP_ENV=production; este comando levanta esa prohibición SOLO para su propia
 * llamada a migrate:fresh/db:wipe y la restaura al estado previo al terminar
 * (incluso si falla) — ver conComandosDestructivosPermitidos(). La protección
 * global y APP_ENV no se tocan.
 *
 * NO toca los archivos del NAS/Synology (PDFs de expedientes): esos viven en
 * disco; este comando solo vacía la base de datos.
 *
 * Es DESTRUCTIVO e irreversible. Pide confirmación escrita salvo --force.
 */
class ResetProduccionCommand extends Command
{
    protected $signature = 'people:reset-produccion
        {--seed : (Retirada) Ya no siembra nada: usa php artisan people:bootstrap-produccion}
        {--force : Omitir la confirmación escrita (úsalo solo en automatización)}';

    protected $description = 'Borra TODA la base de datos de PEOPLE y reaplica migraciones (sin sembrar nada) para arrancar con datos reales.';

    private const FRASE = 'BORRAR TODO';

    public function handle(): int
    {
        if ($this->option('seed')) {
            $this->components->error('La opción --seed ya no existe: este comando solo reinicia la base. Repite sin --seed y después corre: php artisan people:bootstrap-produccion');

            return self::FAILURE;
        }

        $conexion = DB::connection();
        $nombreConexion = (string) $conexion->getName();
        $base = (string) $conexion->getDatabaseName();
        $entorno = (string) app()->environment();

        $this->newLine();
        $this->components->warn('Esto BORRA TODA la base de datos de PEOPLE y es IRREVERSIBLE.');
        $this->components->twoColumnDetail('<fg=gray>Entorno</>', $entorno);
        $this->components->twoColumnDetail('<fg=gray>Conexión</>', $nombreConexion);
        $this->components->twoColumnDetail('<fg=gray>Base de datos</>', "<options=bold>{$base}</>");
        $this->components->twoColumnDetail('<fg=gray>Se borrará</>', 'colaboradores, usuarios, expedientes, documentos, solicitudes, nómina, todo');
        $this->components->twoColumnDetail('<fg=gray>NO se toca</>', 'los archivos del NAS/Synology (solo la base de datos)');
        $this->components->twoColumnDetail('<fg=gray>NO se siembra</>', 'nada (ni catálogos, ni roles, ni datos demo)');
        $this->newLine();

        if (! $this->option('force')) {
            $respuesta = (string) $this->ask(sprintf('Para confirmar, escribe exactamente «%s»', self::FRASE));

            if (trim($respuesta) !== self::FRASE) {
                $this->components->warn('Texto incorrecto: no se borró nada.');

                return self::FAILURE;
            }
        }

        // Si falla se detiene aquí (nunca se reporta «reiniciada» con la base
        // a medias): repite el comando tras corregir la causa.
        $migro = false;
        $this->components->task('Borrando y reaplicando migraciones (migrate:fresh)', function () use (&$migro, $nombreConexion): bool {
            $migro = self::conComandosDestructivosPermitidos(
                fn (): int => $this->call('migrate:fresh', ['--database' => $nombreConexion, '--force' => true]),
            ) === self::SUCCESS;

            return $migro;
        });

        if (! $migro) {
            $this->components->error('migrate:fresh falló: revisa el error de arriba. La base puede haber quedado a medias; repite el comando.');

            return self::FAILURE;
        }

        $this->components->task('Limpiando cachés (config, route, cache, view)', function (): bool {
            $this->callSilent('config:clear');
            $this->callSilent('route:clear');
            $this->callSilent('cache:clear');
            $this->callSilent('view:clear');

            return true;
        });

        $this->newLine();
        $this->components->info('Base de datos reiniciada (vacía, solo con la estructura de tablas).');
        $this->line('  Siguiente paso: <options=bold>php artisan people:bootstrap-produccion</> (roles, empresa, sucursales y catálogos técnicos).');

        return self::SUCCESS;
    }

    /**
     * Ejecuta $accion con migrate:fresh y db:wipe permitidos aunque el
     * entorno sea producción, y al terminar (bien o mal) deja la prohibición
     * EXACTAMENTE como estaba. db:wipe se incluye porque migrate:fresh lo
     * invoca internamente para borrar las tablas.
     *
     * @template T
     *
     * @param  callable(): T  $accion
     * @return T
     */
    public static function conComandosDestructivosPermitidos(callable $accion): mixed
    {
        $freshProhibido = self::estaProhibido(FreshCommand::class);
        $wipeProhibido = self::estaProhibido(WipeCommand::class);

        FreshCommand::prohibit(false);
        WipeCommand::prohibit(false);

        try {
            return $accion();
        } finally {
            FreshCommand::prohibit($freshProhibido);
            WipeCommand::prohibit($wipeProhibido);
        }
    }

    /**
     * Laravel no expone un getter del estado de Prohibitable: se lee la
     * propiedad estática por reflexión (cada clase que usa el trait tiene
     * su propia copia).
     *
     * @param  class-string<FreshCommand|WipeCommand>  $comando
     */
    public static function estaProhibido(string $comando): bool
    {
        return (bool) (new ReflectionProperty($comando, 'prohibitedFromRunning'))->getValue();
    }
}
