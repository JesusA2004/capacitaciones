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
 * usar". No modifica nada: solo reporta. Pensado para correrse justo después
 * de `migrate --force` + `db:seed --force` en un deploy, o cuando algo no
 * carga y no está claro si falta un import/seed — ver docs/DEPLOY.md.
 */
class PeopleDiagnosticoCommand extends Command
{
    protected $signature = 'people:diagnostico';

    protected $description = 'Revisa columnas críticas, storage, catálogos y datos demo del portal RH sin modificar nada';

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

        $esperados = (new ReflectionClass(RolesYPermisosSeeder::class))->getConstant('PERMISOS');
        $existentes = Permission::query()->pluck('name')->all();
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

        $esperados = array_keys((new ReflectionClass(RolesYPermisosSeeder::class))->getConstant('ROLES'));
        $existentes = Role::query()->pluck('name')->all();
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
