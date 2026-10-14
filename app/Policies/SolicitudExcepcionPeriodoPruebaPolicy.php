<?php

namespace App\Policies;

use App\Models\SolicitudExcepcionPeriodoPrueba;
use App\Models\User;
use App\Policies\Concerns\ConSuperAdminOperativo;
use App\Services\Contratos\SolicitudExcepcionPeriodoPruebaService;

/**
 * Resolver (aprobar/rechazar) una excepción a la regla canónica de
 * renovación (FO-RH-EPP-001) es EXCLUSIVO de Dirección General — nunca
 * Regional ni Dirección Comercial, ni quien la solicitó. super_admin manda
 * sobre esa exclusividad igual que sobre el resto de People, ver
 * ConSuperAdminOperativo.
 */
class SolicitudExcepcionPeriodoPruebaPolicy
{
    use ConSuperAdminOperativo;

    public function resolver(User $usuario, SolicitudExcepcionPeriodoPrueba $solicitud): bool
    {
        return $usuario->can(SolicitudExcepcionPeriodoPruebaService::PERMISO_AUTORIZAR)
            && $usuario->id !== $solicitud->solicitante_id;
    }

    public function ver(User $usuario, SolicitudExcepcionPeriodoPrueba $solicitud): bool
    {
        return $usuario->can(SolicitudExcepcionPeriodoPruebaService::PERMISO_AUTORIZAR)
            || $usuario->id === $solicitud->solicitante_id;
    }
}
