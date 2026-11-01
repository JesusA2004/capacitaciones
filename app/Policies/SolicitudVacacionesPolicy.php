<?php

namespace App\Policies;

use App\Models\SolicitudVacaciones;
use App\Models\User;
use App\Policies\Concerns\ConSuperAdminOperativo;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Organigrama\AutoridadJerarquicaService;
use Illuminate\Auth\Access\Response;

class SolicitudVacacionesPolicy
{
    use ConSuperAdminOperativo;

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

    public function aprobar(User $usuario, SolicitudVacaciones $solicitud): bool|Response
    {
        if (! $usuario->can('vacaciones.aprobar') || ! $this->alcance->puedeVerUsuario($usuario, $solicitud->usuario)) {
            return false;
        }

        return $this->respetaLoDecidido($usuario, $solicitud);
    }

    public function rechazar(User $usuario, SolicitudVacaciones $solicitud): bool|Response
    {
        if (! $usuario->can('vacaciones.rechazar') || ! $this->alcance->puedeVerUsuario($usuario, $solicitud->usuario)) {
            return false;
        }

        return $this->respetaLoDecidido($usuario, $solicitud);
    }

    /**
     * Nunca se cambia lo que ya decidió un puesto superior de la línea comercial.
     */
    private function respetaLoDecidido(User $usuario, SolicitudVacaciones $solicitud): bool|Response
    {
        $autoridad = app(AutoridadJerarquicaService::class);

        return $autoridad->puedeModificarLoHechoPor($usuario, $autoridad->autorPorId($solicitud->revisado_por))
            ? true
            : Response::deny(AutoridadJerarquicaService::MENSAJE_PUESTO_SUPERIOR);
    }
}
