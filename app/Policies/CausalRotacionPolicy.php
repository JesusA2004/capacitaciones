<?php

namespace App\Policies;

use App\Models\CausalRotacion;
use App\Models\User;
use App\Services\CierreLaboral\CausalRotacionService;

/**
 * Causales de rotación (subcatálogo del tipo de baja): las administra RH
 * desde Bajas y finiquitos con `finiquitos.causales_rotacion` (además de
 * ver finiquitos). Elegirlas al iniciar una baja no requiere este permiso.
 */
class CausalRotacionPolicy
{
    public function administrar(User $user): bool
    {
        return $user->can('finiquitos.ver') && $user->can(CausalRotacionService::PERMISO_ADMINISTRAR);
    }

    public function create(User $user): bool
    {
        return $this->administrar($user);
    }

    public function update(User $user, CausalRotacion $causal): bool
    {
        return $this->administrar($user);
    }

    public function delete(User $user, CausalRotacion $causal): bool
    {
        return $this->administrar($user);
    }
}
