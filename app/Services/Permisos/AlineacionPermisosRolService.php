<?php

namespace App\Services\Permisos;

use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Contraparte DESTRUCTIVA y explícita de SincronizadorPermisosService: deja
 * a los roles indicados con EXACTAMENTE sus permisos base del seeder
 * (RolesYPermisosSeeder::permisosBasePorRol()), revocando todo lo demás.
 *
 * El sincronizador nunca quita permisos (protege lo que un administrador
 * agregó desde «Roles y permisos»), así que cuando el negocio decide QUITAR
 * permisos a un rol —p. ej. las coordinadoras pasan a usar PEOPLE como
 * colaboradoras— quitar la línea del seeder no basta en producción. Este
 * servicio se invoca SOLO desde una migración de datos que nombra los roles
 * afectados; nunca se aplica a todos los roles.
 *
 * Idempotente: correrlo dos veces deja lo mismo. Si el rol no existe (base
 * nueva antes de sembrar), no hace nada con ese rol.
 */
class AlineacionPermisosRolService
{
    private const GUARD = 'web';

    public function __construct(private readonly PermissionRegistrar $registrar) {}

    /**
     * @param  list<string>  $roles
     * @return array<string, list<string>> permisos revocados por rol
     */
    public function alinearAlBase(array $roles): array
    {
        $base = RolesYPermisosSeeder::permisosBasePorRol();

        foreach ($roles as $nombre) {
            if (! isset($base[$nombre])) {
                throw new InvalidArgumentException(sprintf('El rol «%s» no está en el catálogo del seeder.', $nombre));
            }
        }

        $revocados = [];

        DB::transaction(function () use ($roles, $base, &$revocados): void {
            foreach ($roles as $nombre) {
                $rol = Role::query()->where('name', $nombre)->where('guard_name', self::GUARD)->first();

                if ($rol === null) {
                    continue;
                }

                foreach ($base[$nombre] as $permiso) {
                    Permission::query()->firstOrCreate(['name' => $permiso, 'guard_name' => self::GUARD]);
                }

                $this->registrar->forgetCachedPermissions();

                $actuales = $rol->permissions()->pluck('name')->map(fn ($p): string => (string) $p)->all();
                $revocados[$nombre] = array_values(array_diff($actuales, $base[$nombre]));

                $rol->syncPermissions($base[$nombre]);
            }
        });

        $this->registrar->forgetCachedPermissions();

        return $revocados;
    }
}
