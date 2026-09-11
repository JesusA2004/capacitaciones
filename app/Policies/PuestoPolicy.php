<?php

namespace App\Policies;

use App\Models\Puesto;
use App\Models\User;

class PuestoPolicy
{
    /**
     * El árbol de Organigrama (misma pantalla que "jerarquía de puestos")
     * también lo puede consultar quien solo tiene `organigrama.ver` (p. ej.
     * gerentes/coordinadoras): no administran el catálogo de puestos, pero
     * sí necesitan ver quién ocupa qué y dónde hay vacantes. Editar sigue
     * reservado a quien administra puestos u organigrama explícitamente.
     */
    public function viewAny(User $usuario): bool
    {
        return $usuario->can('puestos.administrar') || $usuario->can('organigrama.ver');
    }

    public function view(User $usuario, Puesto $puesto): bool
    {
        return $usuario->can('puestos.administrar') || $usuario->can('organigrama.ver');
    }

    public function create(User $usuario): bool
    {
        return $usuario->can('puestos.administrar');
    }

    public function update(User $usuario, Puesto $puesto): bool
    {
        return $usuario->can('puestos.administrar') || $usuario->can('organigrama.editar');
    }

    public function delete(User $usuario, Puesto $puesto): bool
    {
        return $usuario->can('puestos.administrar');
    }
}
