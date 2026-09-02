<?php

namespace App\Policies;

use App\Models\SolicitudVacaciones;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;

class SolicitudVacacionesPolicy
{
    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    /**
     * Gate del listado de revision de RH/gerencia (Rh\VacacionesController),
     * no del listado propio del colaborador (que no requiere autorizacion
     * adicional mas alla de estar autenticado).
     */
    public function viewAny(User $usuario): bool
    {
        return $usuario->can('vacaciones.aprobar') || $usuario->can('vacaciones.rechazar');
    }

    public function view(User $usuario, SolicitudVacaciones $solicitud): bool
    {
        if ($usuario->is($solicitud->usuario)) {
            return true;
        }

        return $usuario->can('vacaciones.ver') && $this->alcance->puedeVerUsuario($usuario, $solicitud->usuario);
    }

    public function create(User $usuario): bool
    {
        return $usuario->can('vacaciones.solicitar');
    }

    public function cancelar(User $usuario, SolicitudVacaciones $solicitud): bool
    {
        return $usuario->is($solicitud->usuario);
    }

    public function aprobar(User $usuario, SolicitudVacaciones $solicitud): bool
    {
        return $usuario->can('vacaciones.aprobar') && $this->alcance->puedeVerUsuario($usuario, $solicitud->usuario);
    }

    public function rechazar(User $usuario, SolicitudVacaciones $solicitud): bool
    {
        return $usuario->can('vacaciones.rechazar') && $this->alcance->puedeVerUsuario($usuario, $solicitud->usuario);
    }
}
