<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VinculoReportes;

/**
 * Seguimiento (docs/SEGUIMIENTO.md):
 *   - seguimiento.ver: entrar a los tableros (el alcance de sucursales lo
 *     resuelve AlcanceSeguimientoService con el módulo «seguimiento»).
 *   - seguimiento.vincular: administrar los vínculos PEOPLE ↔ Reportes.
 */
class VinculoReportesPolicy
{
    public function ver(User $user): bool
    {
        return $user->can('seguimiento.ver');
    }

    public function vincular(User $user): bool
    {
        return $user->can('seguimiento.vincular');
    }

    public function desvincular(User $user, VinculoReportes $vinculo): bool
    {
        return $vinculo->activo && $user->can('seguimiento.vincular');
    }
}
