<?php

namespace App\Policies;

use App\Models\CampanaReclutamiento;
use App\Models\User;
use App\Models\Vacante;
use App\Policies\Concerns\ConSuperAdminOperativo;
use App\Services\Permisos\AlcanceModuloService;

/**
 * Campañas de reclutamiento: permiso de la acción (Roles y permisos →
 * Campañas) + alcance por sucursal del módulo `campanas`
 * (AlcanceModuloService; sin permisos de alcance propios se usa el alcance
 * histórico por rol: RH, Sistemas y corporativo ven todo, gerencias solo sus
 * sucursales). Una campaña sin sucursal (campaña general) la ve cualquiera
 * con acceso al módulo.
 */
class CampanaReclutamientoPolicy
{
    use ConSuperAdminOperativo;

    public const MODULO = 'campanas';

    public function __construct(private readonly AlcanceModuloService $alcance) {}

    public function view(User $usuario, CampanaReclutamiento $campana): bool
    {
        return $usuario->can('reclutamiento.campanas.ver') && $this->enAlcance($usuario, $campana->sucursal_id);
    }

    public function exportar(User $usuario, CampanaReclutamiento $campana): bool
    {
        return $this->view($usuario, $campana) && $usuario->can('reclutamiento.campanas.exportar');
    }

    public function update(User $usuario, CampanaReclutamiento $campana): bool
    {
        return $this->view($usuario, $campana) && $usuario->can('reclutamiento.campanas.editar');
    }

    public function delete(User $usuario, CampanaReclutamiento $campana): bool
    {
        return $this->view($usuario, $campana) && $usuario->can('reclutamiento.campanas.eliminar');
    }

    /** Ligar (al crear o editar) una campaña a esta vacante: debe estar en su alcance. */
    public function usarVacante(User $usuario, Vacante $vacante): bool
    {
        return $usuario->can('reclutamiento.campanas.ver') && $this->enAlcance($usuario, $vacante->sucursal_id);
    }

    private function enAlcance(User $usuario, ?int $sucursalId): bool
    {
        return $this->alcance->alcanzaSucursal($usuario, $sucursalId, self::MODULO);
    }
}
