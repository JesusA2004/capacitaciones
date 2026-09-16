<?php

namespace App\Policies;

use App\Models\User;
use App\Services\AlcanceOrganizacionalService;

class UserPolicy
{
    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    public function viewAny(User $usuario): bool
    {
        return $usuario->can('usuarios.ver');
    }

    public function view(User $usuario, User $objetivo): bool
    {
        return $usuario->can('usuarios.ver') && $this->alcance->puedeVerUsuario($usuario, $objetivo);
    }

    public function create(User $usuario): bool
    {
        return $usuario->can('usuarios.crear');
    }

    public function update(User $usuario, User $objetivo): bool
    {
        return $usuario->can('usuarios.editar') && $this->alcance->puedeVerUsuario($usuario, $objetivo);
    }

    public function delete(User $usuario, User $objetivo): bool
    {
        return $usuario->can('usuarios.desactivar')
            && ! $usuario->is($objetivo)
            && $this->alcance->puedeVerUsuario($usuario, $objetivo);
    }

    /**
     * Reactivar una baja lógica es más sensible que darla de alta: solo
     * super_admin la tiene en el catálogo de permisos (ver
     * RolesYPermisosSeeder), a propósito.
     */
    public function reactivar(User $usuario, User $objetivo): bool
    {
        return $usuario->can('usuarios.reactivar')
            && $this->alcance->puedeVerUsuario($usuario, $objetivo);
    }

    /**
     * Revocar/restablecer acceso al sistema (bloquear login sin dar de baja
     * laboral) reutiliza el permiso de "desactivar" -- es una accion MAS
     * ligera que la baja completa (delete()), nunca mas restrictiva.
     */
    public function revocarAcceso(User $usuario, User $objetivo): bool
    {
        return $usuario->can('usuarios.desactivar')
            && ! $usuario->is($objetivo)
            && $this->alcance->puedeVerUsuario($usuario, $objetivo);
    }

    public function restablecerAcceso(User $usuario, User $objetivo): bool
    {
        return $usuario->can('usuarios.desactivar')
            && $this->alcance->puedeVerUsuario($usuario, $objetivo);
    }

    /**
     * Establecer una contraseña nueva desde el panel (pestaña "Usuario" del
     * expediente): nunca se puede leer la contraseña actual (se guarda con
     * hash), solo sobreescribirla. Reutiliza el permiso de "editar" — quien
     * puede editar los datos de un colaborador también puede resetear su
     * acceso.
     */
    public function restablecerPassword(User $usuario, User $objetivo): bool
    {
        return $usuario->can('usuarios.editar')
            && ! $usuario->is($objetivo)
            && $this->alcance->puedeVerUsuario($usuario, $objetivo);
    }
}
