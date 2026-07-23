<?php

namespace App\Policies;

use App\Models\Empresa;
use App\Models\User;

class EmpresaPolicy
{
    public function viewAny(User $usuario): bool
    {
        return $usuario->can('empresas.ver');
    }

    public function view(User $usuario, Empresa $empresa): bool
    {
        return $usuario->can('empresas.ver');
    }

    public function create(User $usuario): bool
    {
        return $usuario->can('empresas.crear');
    }

    public function update(User $usuario, Empresa $empresa): bool
    {
        return $usuario->can('empresas.editar');
    }

    public function delete(User $usuario, Empresa $empresa): bool
    {
        return $usuario->can('empresas.eliminar');
    }
}
