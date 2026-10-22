<?php

namespace App\Policies;

use App\Enums\EstadoCargaRotacion;
use App\Models\RotacionCarga;
use App\Models\User;

/**
 * Rotación de personal cargada desde Excel: consultar (`rotacion.ver`,
 * con el alcance del módulo «rotacion») y cargar/confirmar/descartar
 * (`rotacion.cargar`). Ver config/permisos_modulos.php.
 */
class RotacionCargaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('rotacion.ver');
    }

    public function create(User $user): bool
    {
        return $user->can('rotacion.ver') && $user->can('rotacion.cargar');
    }

    public function view(User $user, RotacionCarga $carga): bool
    {
        return $this->create($user);
    }

    /** Formato de carga y Excel original: acceso + acción (rotacion.descargar). */
    public function descargar(User $user): bool
    {
        return $user->can('rotacion.ver') && $user->can('rotacion.descargar');
    }

    public function aplicar(User $user, RotacionCarga $carga): bool
    {
        return $this->create($user) && $carga->estado === EstadoCargaRotacion::Analizada;
    }

    public function descartar(User $user, RotacionCarga $carga): bool
    {
        return $this->aplicar($user, $carga);
    }
}
