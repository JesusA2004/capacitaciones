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
}
