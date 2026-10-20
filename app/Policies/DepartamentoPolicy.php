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

    /**
     * Mover personas de este departamento a otro: es un cambio de datos
     * laborales en el expediente, así que además del catálogo exige
     * `expedientes.editar` (RH / super administración). El alcance y «nadie
     * se mueve a sí mismo» se revisan por persona en
     * MovimientoDepartamentoService.
     */
    public function moverColaboradores(User $usuario, Departamento $departamento): bool
    {
        return $usuario->can('departamentos.administrar') && $usuario->can('expedientes.editar');
    }
}
