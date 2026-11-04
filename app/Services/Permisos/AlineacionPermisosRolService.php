<?php

namespace App\Services\Permisos;

use App\Services\Auditoria\AuditoriaService;
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

    public function __construct(
        private readonly PermissionRegistrar $registrar,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * Ajuste QUIRÚRGICO (a diferencia de alinearAlBase): otorga y revoca solo
     * los permisos nombrados, sin tocar nada más de cada rol —lo que un
     * administrador haya agregado o quitado desde «Roles y permisos» se
     * respeta—. Un rol inexistente se omite. Cada rol que cambia queda en la
     * bitácora (permisos_rol_ajustados) con el motivo.
     *
     * Idempotente: otorgar lo que ya tiene o revocar lo que no tiene no hace nada.
     *
     * @param  array<string, list<string>>  $otorgar  rol => permisos
     * @param  array<string, list<string>>  $revocar  rol => permisos
     * @param  list<string>  $revocarDeTodos  permisos que se quitan a TODOS los roles
     * @return array{otorgados: array<string, list<string>>, revocados: array<string, list<string>>}
     */
    public function ajustar(array $otorgar, array $revocar, string $motivo, array $revocarDeTodos = []): array
    {
        $resultado = ['otorgados' => [], 'revocados' => []];

        DB::transaction(function () use ($otorgar, $revocar, $revocarDeTodos, &$resultado): void {
            foreach ($otorgar as $nombre => $permisos) {
                $rol = $this->rol($nombre);

                if ($rol === null) {
                    continue;
                }

                $actuales = $this->permisosDe($rol);
                $faltantes = array_values(array_diff(array_unique($permisos), $actuales));

                foreach ($faltantes as $permiso) {
                    Permission::query()->firstOrCreate(['name' => $permiso, 'guard_name' => self::GUARD]);
                }

                if ($faltantes !== []) {
                    $this->registrar->forgetCachedPermissions();
                    $rol->givePermissionTo($faltantes);
                    $resultado['otorgados'][$nombre] = $faltantes;
                }
            }

            $porRol = $revocar;

            if ($revocarDeTodos !== []) {
                foreach (Role::query()->where('guard_name', self::GUARD)->pluck('name') as $nombre) {
                    $porRol[(string) $nombre] = [...($porRol[(string) $nombre] ?? []), ...$revocarDeTodos];
                }
            }

            foreach ($porRol as $nombre => $permisos) {
                $rol = $this->rol($nombre);

                if ($rol === null) {
                    continue;
                }

                $quitar = array_values(array_intersect(array_unique($permisos), $this->permisosDe($rol)));

                foreach ($quitar as $permiso) {
                    $rol->revokePermissionTo($permiso);
                }

                if ($quitar !== []) {
                    $resultado['revocados'][$nombre] = $quitar;
                }
            }
        });

        $this->registrar->forgetCachedPermissions();

        foreach (array_unique([...array_keys($resultado['otorgados']), ...array_keys($resultado['revocados'])]) as $nombre) {
            $this->auditoria->registrar('permisos_rol_ajustados', $this->rol((string) $nombre), null, [
                'rol' => $nombre,
                'motivo' => $motivo,
                'otorgados' => $resultado['otorgados'][$nombre] ?? [],
                'revocados' => $resultado['revocados'][$nombre] ?? [],
                'origen' => 'migracion',
            ]);
        }

        return $resultado;
    }

    private function rol(string $nombre): ?Role
    {
        return Role::query()->where('name', $nombre)->where('guard_name', self::GUARD)->first();
    }

    /**
     * @return list<string>
     */
    private function permisosDe(Role $rol): array
    {
        return array_values($rol->permissions()->pluck('name')->map(fn ($p): string => (string) $p)->all());
    }

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
