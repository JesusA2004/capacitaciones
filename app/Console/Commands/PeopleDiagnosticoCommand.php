<?php

namespace App\Console\Commands;

use App\Enums\TipoNodoComercial;
use App\Models\HeadcountTarget;
use App\Models\OfficialFormat;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * Diagnóstico previo a considerar un entorno (dev/staging/VPS) "listo para
 * usar". Diagnóstico no destructivo; realiza una escritura temporal para
 * comprobar NAS (crea y borra un archivo de prueba en el disco «nas», ver
 * revisarStorageNas()) pero no toca ninguna tabla ni catálogo. Pensado para
 * correrse justo después de `migrate --force` + `db:seed --force` en un
 * deploy, o cuando algo no carga y no está claro si falta un import/seed —
 * ver docs/DEPLOY.md.
 *
 * Cada sección debe llegar hasta el final aunque falte una tabla/catálogo
 * completo (headcount_targets, official_formats, nodos_comerciales,
 * permissions, roles, etc.): por eso cada una se protege con
 * Schema::hasTable() o try/catch en vez de dejar que una excepción SQL
 * detenga el resto del diagnóstico a medias.
 */
class PeopleDiagnosticoCommand extends Command
{
    protected $signature = 'people:diagnostico';

    protected $description = 'Revisa columnas críticas, storage, catálogos y datos demo del portal RH sin modificar nada (solo escribe un archivo temporal de prueba en el disco NAS)';

    /**
     * Tabla => columnas que deben existir. Ver docs/DEPLOY.md, sección
     * "Verificar columnas críticas" — estas son las que distintas fases del
     * encargo agregaron sobre una base ya consolidada.
     *
     * @var array<string, array<int, string>>
     */
    private const COLUMNAS_CRITICAS = [
        'finiquito_calculos' => [],
        'vacantes' => ['plazas_requeridas', 'plazas_cubiertas', 'plazas_disponibles', 'motivo_cancelacion'],
        'nodos_comerciales' => [],
        'user_nodo_comercial' => [],
        'official_formats' => [],
        'official_format_generations' => [],
        'solicitudes_internas' => ['fecha_efectiva', 'tipo_baja', 'colaborador_objetivo_id'],
        'mobile_devices' => [],
        'users' => ['preferencias_ui'],
    ];

    private bool $huboProblemas = false;

    public function handle(): int
    {
        $this->revisarColumnasCriticas();
        $this->revisarStorageNas();
        $this->revisarHeadcount();
        $this->revisarFormatosOficiales();
        $this->revisarColaboradoresIncompletos();
        $this->revisarRutasMatriz();
        $this->revisarPermisos();
        $this->revisarRolesDemo();

        if ($this->huboProblemas) {
            $this->newLine();
            $this->warn('Diagnóstico terminado con advertencias — revisa lo marcado arriba.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Diagnóstico terminado sin problemas.');

        return self::SUCCESS;
    }

    private function revisarColumnasCriticas(): void
    {
        $this->line('<fg=blue>Columnas/tablas críticas</>');

        foreach (self::COLUMNAS_CRITICAS as $tabla => $columnas) {
            if (! Schema::hasTable($tabla)) {
                $this->fallo("Falta la tabla «{$tabla}» — ¿faltó correr `php artisan migrate`?");

                continue;
            }

            $this->ok("Tabla «{$tabla}» existe.");

            foreach ($columnas as $columna) {
                if (Schema::hasColumn($tabla, $columna)) {
                    continue;
                }

                $this->fallo("Falta la columna «{$tabla}.{$columna}» — migración pendiente o corrida a medias.");
            }
        }
    }

    private function revisarStorageNas(): void
    {
        $this->newLine();
        $this->line('<fg=blue>Storage (disco nas)</>');

        $ruta = 'diagnostico/'.uniqid('write-test-', true).'.tmp';

        try {
            Storage::disk('nas')->put($ruta, 'ok');
            Storage::disk('nas')->delete($ruta);
            $this->ok('El disco «nas» es escribible.');
        } catch (Throwable $e) {
            $this->fallo('El disco «nas» no es escribible: '.$e->getMessage());
        }
    }

    private function revisarHeadcount(): void
    {
        $this->newLine();
        $this->line('<fg=blue>Headcount</>');

        if (! Schema::hasTable('headcount_targets')) {
            $this->fallo('Falta la tabla «headcount_targets» — ¿faltó correr `php artisan migrate`?');

            return;
        }

        $total = HeadcountTarget::query()->count();

        if ($total === 0) {
            $this->fallo('No hay headcount cargado (tabla «headcount_targets» vacía) — corre `php artisan headcount:importar`.');

            return;
        }

        $this->ok("Headcount cargado: {$total} renglón(es) de plantilla autorizada.");
    }

    private function revisarFormatosOficiales(): void
    {
        $this->newLine();
        $this->line('<fg=blue>Formatos oficiales</>');

        if (! Schema::hasTable('official_formats')) {
            $this->fallo('Falta la tabla «official_formats» — ¿faltó correr `php artisan migrate`?');

            return;
        }

        $total = OfficialFormat::query()->count();

        if ($total === 0) {
            $this->fallo('No hay formatos oficiales importados — coloca los PDFs en claude/formatos/originales/ y corre `php artisan formatos:importar-originales`.');

            return;
        }

        $sinConfigurar = OfficialFormat::query()->whereNull('overlay_config')->orWhere('overlay_config', '[]')->count();
        $this->ok("Formatos oficiales importados: {$total} (sin configurar: {$sinConfigurar}).");
    }

    private function revisarColaboradoresIncompletos(): void
    {
        $this->newLine();
        $this->line('<fg=blue>Colaboradores activos con datos incompletos</>');

        $campos = [
            'puesto_id' => 'puesto',
            'sucursal_principal_id' => 'sucursal',
            'departamento_id' => 'departamento',
            'genero' => 'género',
            'fecha_nacimiento' => 'fecha de nacimiento',
        ];

        foreach ($campos as $columna => $etiqueta) {
            $cantidad = User::query()->where('estatus', 'activo')->whereNull($columna)->count();

            if ($cantidad > 0) {
                $this->fallo("{$cantidad} colaborador(es) activo(s) sin {$etiqueta}.");

                continue;
            }

            $this->ok("Todos los colaboradores activos tienen {$etiqueta}.");
        }
    }

    private function revisarRutasMatriz(): void
    {
        $this->newLine();
        $this->line('<fg=blue>Matriz comercial</>');

        if (! Schema::hasTable('nodos_comerciales')) {
            $this->fallo('Falta la tabla «nodos_comerciales» — ¿faltó correr `php artisan migrate`?');

            return;
        }

        $rutas = DB::table('nodos_comerciales')->where('tipo', TipoNodoComercial::Ruta->value)->count();

        if ($rutas === 0) {
            $this->fallo('No hay rutas cargadas en la matriz comercial (nodos_comerciales.tipo = ruta).');

            return;
        }

        $this->ok("Matriz comercial: {$rutas} ruta(s) cargada(s).");
    }

    private function revisarPermisos(): void
    {
        $this->newLine();
        $this->line('<fg=blue>Permisos</>');

        $tablaPermisos = config('permission.table_names.permissions', 'permissions');

        if (! is_string($tablaPermisos) || ! Schema::hasTable($tablaPermisos)) {
            $this->fallo("Falta la tabla «{$tablaPermisos}» — ¿faltó correr `php artisan migrate`?");

            return;
        }

        $reflexion = new ReflectionClass(RolesYPermisosSeeder::class);
        $esperados = [
            ...$reflexion->getConstant('PERMISOS'),
            ...$reflexion->getConstant('PERMISOS_PERSONALES'),
        ];

        try {
            $existentes = Permission::query()->pluck('name')->all();
        } catch (Throwable $e) {
            $this->fallo('No se pudo leer el catálogo de permisos: '.$e->getMessage());

            return;
        }

        $faltantes = array_diff($esperados, $existentes);

        if ($faltantes !== []) {
            $this->fallo(count($faltantes).' permiso(s) del catálogo no están sembrados: '.implode(', ', array_slice($faltantes, 0, 10)).(count($faltantes) > 10 ? '…' : ''));

            return;
        }

        $this->ok(count($esperados).' permisos del catálogo están sembrados.');
    }

    private function revisarRolesDemo(): void
    {
        $this->newLine();
        $this->line('<fg=blue>Roles demo</>');

        $tablaRoles = config('permission.table_names.roles', 'roles');

        if (! is_string($tablaRoles) || ! Schema::hasTable($tablaRoles)) {
            $this->fallo("Falta la tabla «{$tablaRoles}» — ¿faltó correr `php artisan migrate`?");

            return;
        }

        $esperados = array_keys((new ReflectionClass(RolesYPermisosSeeder::class))->getConstant('ROLES'));

        try {
            $existentes = Role::query()->pluck('name')->all();
        } catch (Throwable $e) {
            $this->fallo('No se pudo leer el catálogo de roles: '.$e->getMessage());

            return;
        }

        $faltantes = array_diff($esperados, $existentes);

        if ($faltantes !== []) {
            $this->fallo('Faltan roles: '.implode(', ', $faltantes).' — corre `php artisan db:seed --class=RolesYPermisosSeeder`.');

            return;
        }

        $this->ok(count($esperados).' roles existen ('.implode(', ', $esperados).').');
    }

    private function ok(string $mensaje): void
    {
        $this->line("  <fg=green>✓</> {$mensaje}");
    }

    private function fallo(string $mensaje): void
    {
        $this->huboProblemas = true;
        $this->line("  <fg=red>✗</> {$mensaje}");
    }
}
