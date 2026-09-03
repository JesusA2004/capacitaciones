<?php

namespace App\Policies;

use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;

/**
 * No usa los verbos CRUD por defecto de Laravel: cada método representa una
 * acción real del flujo de revisión (igual criterio que
 * EmployeeDocumentPolicy/SolicitudVacacionesPolicy).
 */
class SolicitudInternaPolicy
{
    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    /**
     * Gate del listado de revisión de RH/gerencia (Rh\SolicitudController),
     * no del listado propio del colaborador.
     */
    public function viewAny(User $usuario): bool
    {
        return $usuario->can('solicitudes.revisar') || $usuario->can('solicitudes.aprobar');
    }

    public function view(User $usuario, SolicitudInterna $solicitud): bool
    {
        if ($usuario->is($solicitud->usuario)) {
            return true;
        }

        return $usuario->can('solicitudes.ver') && $this->alcance->puedeVerUsuario($usuario, $solicitud->usuario);
    }

    public function create(User $usuario): bool
    {
        return $usuario->can('solicitudes.crear');
    }

    public function cancelar(User $usuario, SolicitudInterna $solicitud): bool
    {
        return $usuario->is($solicitud->usuario) && $solicitud->estado->puedeCancelarse();
    }

    /**
     * Marcar en revisión / pedir corrección: acción de RH/gerencia, nunca
     * sobre la propia solicitud.
     */
    public function revisar(User $usuario, SolicitudInterna $solicitud): bool
    {
        return $usuario->can('solicitudes.revisar')
            && ! $usuario->is($solicitud->usuario)
            && $this->alcance->puedeVerUsuario($usuario, $solicitud->usuario);
    }

    public function aprobar(User $usuario, SolicitudInterna $solicitud): bool
    {
        return $usuario->can('solicitudes.aprobar') && $this->revisar($usuario, $solicitud);
    }

    public function rechazar(User $usuario, SolicitudInterna $solicitud): bool
    {
        return $usuario->can('solicitudes.rechazar') && $this->revisar($usuario, $solicitud);
    }

    public function cerrar(User $usuario, SolicitudInterna $solicitud): bool
    {
        return $usuario->can('solicitudes.cerrar') && $this->revisar($usuario, $solicitud);
    }
}
