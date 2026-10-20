<?php

namespace App\Policies;

use App\Models\CoberturaPuesto;
use App\Models\User;

/**
 * Coberturas temporales desde el organigrama («Cubre temporalmente este
 * puesto»): solo RH / super admin (organigrama.editar). Ver los coberturas
 * en el árbol lo puede cualquiera con organigrama.ver (PuestoPolicy).
 */
class CoberturaPuestoPolicy
{
    public function create(User $usuario): bool
    {
        return $usuario->can('organigrama.editar');
    }

    public function finalizar(User $usuario, CoberturaPuesto $cobertura): bool
    {
        return $usuario->can('organigrama.editar');
    }
}
