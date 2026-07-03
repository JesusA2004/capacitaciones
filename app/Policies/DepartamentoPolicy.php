<?php

namespace App\Policies;

use App\Models\Departamento;
use App\Models\User;

class DepartamentoPolicy
{
    public function viewAny(User $usuario): bool
    {
        return $usuario->can('departamentos.administrar');
    }

    public function view(User $usuario, Departamento $departamento): bool
    {
        return $usuario->can('departamentos.administrar');
    }

    public function create(User $usuario): bool
    {
        return $usuario->can('departamentos.administrar');
    }

    public function update(User $usuario, Departamento $departamento): bool
    {
        return $usuario->can('departamentos.administrar');
    }

    public function delete(User $usuario, Departamento $departamento): bool
    {
        return $usuario->can('departamentos.administrar');
    }
}
