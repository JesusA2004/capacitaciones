<?php

namespace App\Services\Permisos;

use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sincronización de permisos segura para producción (ver docs/DEPLOY.md):
 * `git pull` + `migrate` no crean los permisos nuevos del catálogo ni se
 * los dan a los roles existentes — así desapareció "Aniversarios"
 * (`celebraciones.ver`) en producción aunque el seeder ya lo tenía.
 *
 * Reglas:
 *  - firstOrCreate de cada permiso y rol del catálogo
 *    (RolesYPermisosSeeder::catalogoPermisos()/permisosBasePorRol());
 *  - a cada rol solo se le AGREGAN los permisos base que le falten;
 *  - nunca se quita un permiso (un rol editado desde "Roles y permisos"
 *    conserva lo que un administrador le agregó) — nada de
 *    syncPermissions();
 *  - al final se limpia la caché de Spatie (PermissionRegistrar).
 *
 * Idempotente: correrlo dos veces seguidas no cambia nada la segunda vez.
 */
class SincronizadorPermisosService
{
    private const GUARD = 'web';

    public function __construct(private readonly PermissionRegistrar $registrar) {}

    /**
     * @return array{permisos_creados: list<string>, roles_creados: list<string>, permisos_otorgados: array<string, list<string>>}
     */
    public function sincronizar(bool $simular = false): array
    {
        $resultado = ['permisos_creados' => [], 'roles_creados' => [], 'permisos_otorgados' => []];

        $ejecutar = function () use (&$resultado, $simular): void {
            $existentes = Permission::query()->where('guard_name', self::GUARD)->pluck('name')->all();
            $resultado['permisos_creados'] = array_values(array_diff(RolesYPermisosSeeder::catalogoPermisos(), $existentes));

            if (! $simular) {
                foreach ($resultado['permisos_creados'] as $permiso) {
                    Permission::query()->firstOrCreate(['name' => $permiso, 'guard_name' => self::GUARD]);
                }

                $this->registrar->forgetCachedPermissions();
            }

            foreach (RolesYPermisosSeeder::permisosBasePorRol() as $nombreRol => $permisosBase) {
                $rol = Role::query()->where('name', $nombreRol)->where('guard_name', self::GUARD)->first();

                if ($rol === null) {
                    $resultado['roles_creados'][] = $nombreRol;
                    $actuales = [];

                    if (! $simular) {
                        $rol = Role::query()->create(['name' => $nombreRol, 'guard_name' => self::GUARD]);
                    }
                } else {
                    $actuales = $rol->permissions()->pluck('name')->all();
                }

                $faltantes = array_values(array_diff($permisosBase, $actuales));

                if ($faltantes === []) {
                    continue;
                }

                $resultado['permisos_otorgados'][$nombreRol] = $faltantes;

                if ($rol !== null && ! $simular) {
                    $rol->givePermissionTo($faltantes);
                }
            }
        };

        $simular ? $ejecutar() : DB::transaction($ejecutar);

        if (! $simular) {
            $this->registrar->forgetCachedPermissions();
        }

        return $resultado;
    }
}
