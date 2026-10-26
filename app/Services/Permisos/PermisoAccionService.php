<?php

namespace App\Services\Permisos;

use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * ¿Puede este usuario ejecutar ESTA acción? Para permisos por acción que se
 * separaron de uno general (config/permisos_modulos.php → herencia).
 *
 * Mientras el permiso de la acción todavía no existe en la base (producción
 * entre el `git pull` y `people:sincronizar-permisos`), responde con los
 * permisos generales de los que se separó: el despliegue nunca deja a nadie
 * sin poder hacer lo que ya hacía ni le da algo nuevo. En cuanto el permiso
 * existe, manda solo él: lo que se configure en «Roles y permisos».
 */
class PermisoAccionService
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    public function puede(User $usuario, string $permiso): bool
    {
        return $this->evaluar($usuario, $permiso, 0);
    }

    /**
     * @param  list<string>  $permisos
     */
    public function puedeAlguno(User $usuario, array $permisos): bool
    {
        foreach ($permisos as $permiso) {
            if ($this->puede($usuario, $permiso)) {
                return true;
            }
        }

        return false;
    }

    public function existe(string $permiso): bool
    {
        return $this->registrar->getPermissions(['name' => $permiso, 'guard_name' => 'web'], true)->isNotEmpty();
    }

    private function evaluar(User $usuario, string $permiso, int $profundidad): bool
    {
        if ($this->existe($permiso)) {
            return $usuario->can($permiso);
        }

        // Ciclo o cadena absurda en la configuración: se corta sin otorgar.
        if ($profundidad > 5) {
            return false;
        }

        foreach (RolesYPermisosSeeder::herencia()[$permiso] ?? [] as $general) {
            if ($this->evaluar($usuario, $general, $profundidad + 1)) {
                return true;
            }
        }

        return false;
    }
}
