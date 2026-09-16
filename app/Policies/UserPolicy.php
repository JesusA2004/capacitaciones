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

    /**
     * Revocar/restablecer acceso al sistema (bloquear login sin dar de baja
     * laboral) — la baja/reactivación de la relación laboral vive en
     * App\Http\Controllers\Rh\ExpedienteController (actúa sobre Colaborador,
     * funciona con o sin cuenta de acceso), no aquí.
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
