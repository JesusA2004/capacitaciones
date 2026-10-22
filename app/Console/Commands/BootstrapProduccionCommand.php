<?php

namespace App\Console\Commands;

use Database\Seeders\BirthdayPhraseSeeder;
use Database\Seeders\CursoInduccionSeeder;
use Database\Seeders\DepartamentoSeeder;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\EmpresaSeeder;
use Database\Seeders\FormulariosEvaluacionSeeder;
use Database\Seeders\InduccionMrLanaModuloSeeder;
use Database\Seeders\MatrizComercialSeeder;
use Database\Seeders\MotivosRechazoCandidatoSeeder;
use Database\Seeders\PuestoJerarquiaSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Database\Seeders\SucursalSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Throwable;

/**
 * Siembra SOLO los datos técnicos que PEOPLE necesita antes del primer
 * login en una base recién reiniciada (`people:reset-produccion`). Idempotente:
 * cada seeder usa firstOrCreate/updateOrCreate, así que repetirlo no duplica.
 *
 * Qué SÍ siembra (ninguno crea personas, usuarios ni plantilla):
 *   - roles y permisos (RolesYPermisosSeeder = people:sincronizar-permisos);
 *   - empresa Mr. Lana, las 11 sucursales + Corporativo;
 *   - la estructura organizacional CONFIRMADA (departamentos, puestos y
 *     jerarquía de SincronizadorOrganigramaService) que usa el resolutor de
 *     la carga inicial;
 *   - la matriz comercial territorial (MATRIZ → Región → Zona → Ruta): solo
 *     nodos, nunca asigna gestores ni responsables, y el resolutor de la
 *     carga inicial la usa para saber la región de cada sucursal;
 *   - catálogos técnicos: tipos de documento, motivos de rechazo de
 *     candidatos, frases de cumpleaños y contenido de inducción.
 *
 * Qué NO corre, a propósito:
 *   - DemoSeeder ni ningún *Demo* / UsuarioDemoSeeder (personas ficticias);
 *   - PlantillaAutorizadaSeeder (headcount histórico: la plantilla real
 *     llega con el XLSX maestro de la carga inicial);
 *   - CatalogosBaseGeneralSeeder (puestos de la fuente anterior: los reales
 *     llegan con el XLSX maestro);
 *   - PreguntasFiltroCandidatoSeeder (depende de los puestos finales: lo
 *     corre `people:inicializar-datos-reales` después de la carga).
 */
class BootstrapProduccionCommand extends Command
{
    protected $signature = 'people:bootstrap-produccion';

    protected $description = 'Siembra roles, empresa, sucursales, estructura confirmada y catálogos técnicos (sin personas ni demo). Idempotente.';

    /**
     * Seeder => descripción del paso. El orden importa: la empresa antes que
     * las sucursales, departamentos antes que puestos y sucursales/puestos
     * antes que la matriz comercial.
     *
     * @var array<class-string<Seeder>, string>
     */
    private const PASOS = [
        RolesYPermisosSeeder::class => 'Roles y permisos',
        EmpresaSeeder::class => 'Empresa Mr. Lana',
        SucursalSeeder::class => 'Sucursales (11 + Corporativo)',
        DepartamentoSeeder::class => 'Departamentos de la estructura confirmada',
        PuestoJerarquiaSeeder::class => 'Puestos y jerarquía confirmada (organigrama canónico)',
        MatrizComercialSeeder::class => 'Matriz comercial territorial (sin personas)',
        DocumentTypeSeeder::class => 'Tipos de documento del expediente',
        MotivosRechazoCandidatoSeeder::class => 'Motivos de rechazo de candidatos',
        BirthdayPhraseSeeder::class => 'Frases de cumpleaños',
        CursoInduccionSeeder::class => 'Curso de inducción general (contenido)',
        InduccionMrLanaModuloSeeder::class => 'Módulo «Inducción Mr. Lana» (contenido)',
        FormulariosEvaluacionSeeder::class => 'Formularios de evaluación (versión 1)',
    ];

    public function handle(): int
    {
        $this->newLine();
        $this->components->info('Bootstrap técnico de PEOPLE (sin personas, sin plantilla, sin demo).');

        $total = count(self::PASOS);
        $numero = 0;

        foreach (self::PASOS as $clase => $titulo) {
            $numero++;
            $error = null;

            $this->components->task(sprintf('%d/%d %s', $numero, $total, $titulo), function () use ($clase, &$error): bool {
                try {
                    $this->sembrar($clase);

                    return true;
                } catch (Throwable $e) {
                    $error = $e->getMessage();

                    return false;
                }
            });

            if ($error !== null) {
                $this->components->error(sprintf('Falló «%s»: %s', $titulo, $error));
                $this->line('  Corrige la causa y repite <options=bold>php artisan people:bootstrap-produccion</> (es seguro repetirlo).');

                return self::FAILURE;
            }
        }

        $this->components->task('Limpiando caché de la aplicación', fn (): bool => $this->callSilent('cache:clear') === self::SUCCESS);

        $this->newLine();
        $this->components->info('Bootstrap terminado.');
        $this->line('  Siguientes pasos:');
        $this->line('  1. <options=bold>php artisan people:crear-admin</> — tu cuenta super_admin operativa (sin colaborador).');
        $this->line('  2. Entra al portal y sube <options=bold>MR_LANA_PEOPLE_CARGA_INICIAL.xlsx</> en Configuración → Importar datos → Carga inicial PEOPLE.');
        $this->line('  3. <options=bold>php artisan people:inicializar-datos-reales</> — organigrama, descriptivos, formatos, permisos y vacantes.');

        return self::SUCCESS;
    }

    /**
     * Corre un seeder igual que DatabaseSeeder (sin eventos de modelo, con
     * este comando como salida para sus avisos).
     *
     * @param  class-string<Seeder>  $clase
     */
    private function sembrar(string $clase): void
    {
        $seeder = $this->laravel->make($clase);
        $seeder->setContainer($this->laravel)->setCommand($this);

        Model::withoutEvents(fn () => $seeder->__invoke());
    }
}
