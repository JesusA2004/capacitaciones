<?php

namespace App\Policies;

use App\Models\AvisoBajaSistemas;
use App\Models\User;
use App\Policies\Concerns\ConSuperAdminOperativo;
use App\Services\Notificaciones\AvisoBajaSistemasService;

/**
 * Bandeja «Pendientes de baja en sistemas». Mismos permisos que la de alta:
 * ver = sistemas.altas.ver (Sistemas, RH); atender/reabrir =
 * sistemas.altas.atender (Sistemas). El listado se acota por alcance en
 * PendientesBajaSistemasService.
 */
class AvisoBajaSistemasPolicy
{
    use ConSuperAdminOperativo;

    public function viewAny(User $usuario): bool
    {
        return $usuario->can(AvisoBajaSistemasService::PERMISO_VER_BANDEJA);
    }

    public function atender(User $usuario, AvisoBajaSistemas $aviso): bool
    {
        return $usuario->can(AvisoBajaSistemasService::PERMISO_ATENDER);
    }
}
