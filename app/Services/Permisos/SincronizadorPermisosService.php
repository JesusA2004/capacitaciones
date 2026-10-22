<?php

namespace App\Services\Permisos;

use App\Models\User;
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
 *  - los permisos base de un rol se otorgan SOLO cuando el permiso se crea
 *    en esta corrida (o el rol es nuevo). Un permiso que ya existía y que un
 *    administrador le quitó a un rol desde «Roles y permisos» NO se vuelve
 *    a otorgar: la configuración por rol es del negocio, no del deploy;
 *  - herencia (config/permisos_modulos.php): un permiso nuevo que se separó
 *    de uno general se otorga además a todo rol —incluidos los creados a
 *    mano— y a todo usuario con permiso directo que ya tenía el general,
 *    para que dividir un permiso nunca le quite nada a nadie;
 *  - nunca se quita un permiso — nada de syncPermissions() (para revocar
 *    existe AlineacionPermisosRolService, solo desde una migración);
 *  - al final se limpia la caché de Spatie (PermissionRegistrar).
 *
 * Idempotente: correrlo dos veces seguidas no cambia nada la segunda vez.
 */
class SincronizadorPermisosService
{
    private const GUARD = 'web';

    public function __construct(private readonly PermissionRegistrar $registrar) {}

    /**
     * @return array{permisos_creados: list<string>, roles_creados: list<string>, permisos_otorgados: array<string, list<string>>, heredados: array<string, list<string>>}
     */
    public function sincronizar(bool $simular = false): array
    {
        $resultado = ['permisos_creados' => [], 'roles_creados' => [], 'permisos_otorgados' => [], 'heredados' => []];

        $ejecutar = function () use (&$resultado, $simular): void {
            $existentes = Permission::query()->where('guard_name', self::GUARD)->pluck('name')->map(fn ($p): string => (string) $p)->all();
            $creados = array_values(array_diff(RolesYPermisosSeeder::catalogoPermisos(), $existentes));
            $resultado['permisos_creados'] = $creados;

            if (! $simular) {
                foreach ($creados as $permiso) {
                    Permission::query()->firstOrCreate(['name' => $permiso, 'guard_name' => self::GUARD]);
                }

                $this->registrar->forgetCachedPermissions();
            }

            foreach (RolesYPermisosSeeder::permisosBasePorRol() as $nombreRol => $permisosBase) {
                $rol = Role::query()->where('name', $nombreRol)->where('guard_name', self::GUARD)->first();

                if ($rol === null) {
                    // Rol nuevo: arranca con toda su base.
                    $resultado['roles_creados'][] = $nombreRol;
                    $faltantes = $permisosBase;

                    if (! $simular) {
                        $rol = Role::query()->create(['name' => $nombreRol, 'guard_name' => self::GUARD]);
                    }
                } else {
                    // Rol existente: solo los permisos de su base que se
                    // crearon en esta corrida.
                    $actuales = $rol->permissions()->pluck('name')->map(fn ($p): string => (string) $p)->all();
                    $faltantes = array_values(array_diff(array_intersect($permisosBase, $creados), $actuales));
                }

                $faltantes = array_values(array_unique($faltantes));

                if ($faltantes === []) {
                    continue;
                }

                $resultado['permisos_otorgados'][$nombreRol] = $faltantes;

                if ($rol !== null && ! $simular) {
                    $rol->givePermissionTo($faltantes);
                }
            }

            $resultado['heredados'] = $this->otorgarHerencia($creados, $simular);
        };

        $simular ? $ejecutar() : DB::transaction($ejecutar);

        if (! $simular) {
            $this->registrar->forgetCachedPermissions();
        }

        return $resultado;
    }

    /**
     * Otorga cada permiso nuevo con herencia a quien ya tenía el permiso
     * general del que se separó (roles de cualquier nombre y usuarios con
     * permiso directo). Se resuelve en cadena: si una acción hereda de otra
     * que también es nueva, primero se reparte la de arriba.
     *
     * @param  list<string>  $creados
     * @return array<string, list<string>> permiso => roles/usuarios que lo recibieron
     */
    private function otorgarHerencia(array $creados, bool $simular): array
    {
        $herencia = array_intersect_key(RolesYPermisosSeeder::herencia(), array_flip($creados));
        $otorgados = [];
        $pendientes = $herencia;

        while ($pendientes !== []) {
            $avance = false;

            foreach ($pendientes as $nuevo => $existente) {
                // La general todavía espera su propio reparto.
                if (isset($pendientes[$existente])) {
                    continue;
                }

                unset($pendientes[$nuevo]);
                $avance = true;

                $roles = Role::query()->where('guard_name', self::GUARD)
                    ->whereHas('permissions', fn ($q) => $q->where('name', $existente))
                    ->get()
                    ->reject(fn (Role $rol): bool => $rol->permissions()->where('name', $nuevo)->exists());

                $usuarios = User::query()
                    ->whereHas('permissions', fn ($q) => $q->where('name', $existente))
                    ->get()
                    ->reject(fn (User $usuario): bool => $usuario->permissions()->where('name', $nuevo)->exists());

                foreach ($roles as $rol) {
                    $otorgados[$nuevo][] = sprintf('rol %s', $rol->name);

                    if (! $simular) {
                        $rol->givePermissionTo($nuevo);
                    }
                }

                foreach ($usuarios as $usuario) {
                    $otorgados[$nuevo][] = sprintf('usuario %s', $usuario->email);

                    if (! $simular) {
                        $usuario->givePermissionTo($nuevo);
                    }
                }
            }

            // Ciclo en la configuración (A hereda de B y B de A): se corta.
            if (! $avance) {
                break;
            }
        }

        if (! $simular && $otorgados !== []) {
            $this->registrar->forgetCachedPermissions();
        }

        return $otorgados;
    }
}
