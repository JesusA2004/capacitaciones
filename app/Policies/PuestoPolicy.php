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
        return $usuario->can('puestos.administrar') && $usuario->can('puestos.crear');
    }

    /** Catálogo de puestos: editar nombre, departamento, periodo de prueba. */
    public function update(User $usuario, Puesto $puesto): bool
    {
        return $usuario->can('puestos.administrar') && $usuario->can('puestos.editar');
    }

    /**
     * Organigrama: a quién reporta el puesto, tipo, respaldos y puestos que
     * puede cubrir. Lo edita quien edita el organigrama o quien edita el
     * catálogo de puestos (como antes de separar los permisos).
     */
    public function editarJerarquia(User $usuario, Puesto $puesto): bool
    {
        return ($usuario->can('organigrama.ver') && $usuario->can('organigrama.editar')) || $this->update($usuario, $puesto);
    }

    public function delete(User $usuario, Puesto $puesto): bool
    {
        return $usuario->can('puestos.administrar') && $usuario->can('puestos.eliminar');
    }
}
