<?php

namespace App\Policies;

use App\Models\Candidato;
use App\Models\IncorporacionInvitacion;
use App\Models\User;
use App\Policies\Concerns\ConSuperAdminOperativo;
use App\Services\Permisos\AlcanceModuloService;

/**
 * Invitaciones QR de incorporación: primero el permiso de la acción
 * (Roles y permisos → Invitaciones QR) y después el alcance por sucursal del
 * módulo `invitaciones_qr` (AlcanceModuloService; mientras el módulo no
 * tenga permisos de alcance propios se usa el alcance histórico por rol:
 * RH y corporativo ven todo, gerencias solo sus sucursales). Cada operación
 * sobre una invitación concreta se valida aquí, nunca solo en la pantalla.
 */
class IncorporacionInvitacionPolicy
{
    use ConSuperAdminOperativo;

    public const MODULO = 'invitaciones_qr';

    public function __construct(private readonly AlcanceModuloService $alcance) {}

    public function viewAny(User $usuario): bool
    {
        return $usuario->can('rh.incorporacion.invitaciones.ver');
    }

    public function view(User $usuario, IncorporacionInvitacion $invitacion): bool
    {
        return $usuario->can('rh.incorporacion.invitaciones.ver') && $this->enAlcance($usuario, $invitacion->sucursal_id);
    }

    /** Emitir la invitación de un candidato concreto (debe estar en su alcance). */
    public function crearPara(User $usuario, Candidato $candidato): bool
    {
        return $usuario->can('rh.incorporacion.invitaciones.crear') && $this->enAlcance($usuario, $candidato->sucursal_id);
    }

    public function regenerar(User $usuario, IncorporacionInvitacion $invitacion): bool
    {
        return $usuario->can('rh.incorporacion.invitaciones.regenerar') && $this->enAlcance($usuario, $invitacion->sucursal_id);
    }

    public function revocar(User $usuario, IncorporacionInvitacion $invitacion): bool
    {
        return $usuario->can('rh.incorporacion.invitaciones.revocar') && $this->enAlcance($usuario, $invitacion->sucursal_id);
    }

    public function descargarQr(User $usuario, IncorporacionInvitacion $invitacion): bool
    {
        return $usuario->can('rh.incorporacion.invitaciones.qr.descargar') && $this->enAlcance($usuario, $invitacion->sucursal_id);
    }

    private function enAlcance(User $usuario, ?int $sucursalId): bool
    {
        return $this->alcance->alcanzaSucursal($usuario, $sucursalId, self::MODULO);
    }
}
