<?php

namespace App\Policies;

use App\Models\CausalRotacion;
use App\Models\User;
use App\Services\CierreLaboral\CausalRotacionService;

/**
 * Catálogo de causales de baja: lo administra SOLO RH (RN-15) con
 * `finiquitos.causales_rotacion` (además de ver finiquitos), desde Bajas y
 * finiquitos → «Causales» o Configuración → «Catálogo de bajas» (misma
 * pantalla). Elegirlas al registrar una baja no requiere este permiso.
 * Las causales nunca se borran: se desactivan.
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

    /**
     * RN-15: nunca se borra una causal (ni la que nunca se usó).
     */
    public function delete(User $user, CausalRotacion $causal): bool
    {
        return false;
    }
}
