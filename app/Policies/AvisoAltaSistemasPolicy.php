<?php

namespace App\Policies;

use App\Models\AvisoAltaSistemas;
use App\Models\User;
use App\Policies\Concerns\ConSuperAdminOperativo;
use App\Services\Notificaciones\AvisoAltaSistemasService;

/**
 * Bandeja «Pendientes de alta en sistemas». Ver: sistemas.altas.ver
 * (Sistemas, RH); atender/reabrir: sistemas.altas.atender (Sistemas).
 * El listado se acota por alcance en PendientesAltaSistemasService.
 */
class AvisoAltaSistemasPolicy
{
    use ConSuperAdminOperativo;

    public function viewAny(User $usuario): bool
    {
        return $usuario->can(AvisoAltaSistemasService::PERMISO_VER_BANDEJA);
    }

    public function atender(User $usuario, AvisoAltaSistemas $aviso): bool
    {
        return $usuario->can(AvisoAltaSistemasService::PERMISO_ATENDER);
    }
}
