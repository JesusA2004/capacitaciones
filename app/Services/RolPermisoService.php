<?php

namespace App\Services;

use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Roles y sus permisos («Roles y permisos»). Cada cambio queda en la
 * bitácora (AuditoriaService, log "rh") con los permisos agregados y
 * quitados, para saber quién le dio o le quitó qué a un rol.
 */
class RolPermisoService
{
    public function __construct(private readonly AuditoriaService $auditoria) {}

    /**
     * @param  array<int, string>  $permisos
     */
    public function crear(string $nombre, array $permisos, ?User $actor = null): Role
    {
        $rol = DB::transaction(function () use ($nombre, $permisos): Role {
            $rol = Role::query()->create(['name' => $nombre, 'guard_name' => 'web']);
            $rol->syncPermissions($permisos);

            return $rol;
        });

        $this->auditoria->registrar('rol_creado', $rol, $actor, [
            'rol' => $nombre,
            'agregados' => array_values(array_unique($permisos)),
        ]);

        return $rol;
    }

    /**
     * @param  array<int, string>  $permisos
     */
    public function actualizar(Role $rol, string $nombre, array $permisos, ?User $actor = null): Role
    {
        $antes = $this->nombresPermisos($rol);
        $nombreAnterior = (string) $rol->name;

        DB::transaction(function () use ($rol, $nombre, $permisos): void {
            $rol->update(['name' => $nombre]);
            $rol->syncPermissions($permisos);
        });

        $despues = array_values(array_unique($permisos));
        $agregados = array_values(array_diff($despues, $antes));
        $quitados = array_values(array_diff($antes, $despues));

        if ($agregados !== [] || $quitados !== [] || $nombreAnterior !== $nombre) {
            $this->auditoria->registrar('rol_permisos_actualizados', $rol, $actor, [
                'rol' => $nombre,
                'nombre_anterior' => $nombreAnterior !== $nombre ? $nombreAnterior : null,
                'agregados' => $agregados,
                'quitados' => $quitados,
            ]);
        }

        return $rol->fresh() ?? $rol;
    }

    public function clonar(Role $rol, string $nuevoNombre, ?User $actor = null): Role
    {
        $permisos = $this->nombresPermisos($rol);

        $nuevo = DB::transaction(function () use ($rol, $nuevoNombre, $permisos): Role {
            $nuevo = Role::query()->create(['name' => $nuevoNombre, 'guard_name' => $rol->guard_name]);
            $nuevo->syncPermissions($permisos);

            return $nuevo;
        });

        $this->auditoria->registrar('rol_clonado', $nuevo, $actor, [
            'rol' => $nuevoNombre,
            'origen' => (string) $rol->name,
            'agregados' => $permisos,
        ]);

        return $nuevo;
    }

    public function eliminar(Role $rol, ?User $actor = null): void
    {
        $nombre = (string) $rol->name;
        $permisos = $this->nombresPermisos($rol);

        $rol->delete();

        $this->auditoria->registrar('rol_eliminado', null, $actor, [
            'rol' => $nombre,
            'quitados' => $permisos,
        ]);
    }

    /**
     * Roles del seeder (super_admin, rh_admin, gerente…): el código y la
     * configuración los reconocen por su nombre (alcance por rol, Mi
     * espacio, bootstrap móvil), así que no se renombran ni se eliminan
     * desde la pantalla; sus permisos sí se editan.
     */
    public function esRolDelSistema(Role $rol): bool
    {
        return array_key_exists((string) $rol->name, RolesYPermisosSeeder::permisosBasePorRol());
    }

    /**
     * Roles para la pantalla "Roles y permisos", con el número de usuarios y
     * los NOMBRES de sus permisos en `permisos_nombres`. Los nombres se leen
     * con un join plano sobre role_has_permissions: hidratar ~1,300 modelos
     * Permission (con pivote) solo para sacar su nombre costaba ~170 ms.
     *
     * @return EloquentCollection<int, Role>
     */
    public function rolesParaListado(): EloquentCollection
    {
        $tablas = config('permission.table_names');
        $pivote = sprintf('%s', $tablas['role_has_permissions'] ?? 'role_has_permissions');
        $permisos = sprintf('%s', $tablas['permissions'] ?? 'permissions');

        $nombresPorRol = DB::table($pivote)
            ->join($permisos, sprintf('%s.id', $permisos), '=', sprintf('%s.permission_id', $pivote))
            ->orderBy(sprintf('%s.name', $permisos))
            ->get([sprintf('%s.role_id', $pivote), sprintf('%s.name', $permisos)])
            ->groupBy('role_id')
            ->map(fn (Collection $filas) => $filas->pluck('name')->values());

        $roles = Role::query()->withCount('users')->orderBy('name')->get();
        $delSistema = RolesYPermisosSeeder::permisosBasePorRol();

        foreach ($roles as $rol) {
            $rol->setAttribute('permisos_nombres', $nombresPorRol->get($rol->id, collect()));
            $rol->setAttribute('es_del_sistema', array_key_exists((string) $rol->name, $delSistema));
        }

        return $roles;
    }

    /**
     * @param  array<int, string>  $roles
     */
    public function asignarRoles(User $usuario, array $roles): void
    {
        $usuario->syncRoles($roles);
    }

    /**
     * Agrupa los permisos por su modulo (prefijo antes del primer punto).
     *
     * @return Collection<string, EloquentCollection<int, Permission>>
     */
    public function permisosAgrupados(): Collection
    {
        return Permission::query()
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Permission $permiso) => explode('.', $permiso->name)[0]);
    }

    /**
     * @return list<string>
     */
    private function nombresPermisos(Role $rol): array
    {
        return array_values($rol->permissions()->pluck('name')->map(fn ($p): string => (string) $p)->all());
    }
}
