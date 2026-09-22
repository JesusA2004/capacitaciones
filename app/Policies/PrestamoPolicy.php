<?php

namespace App\Policies;

use App\Models\Prestamo;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;

class PrestamoPolicy
{
    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    public function ver(User $usuario, Prestamo $prestamo): bool
    {
        if ($usuario->colaborador_id !== null && $usuario->colaborador_id === $prestamo->colaborador_id) {
            return true;
        }

        return $usuario->can('prestamos.ver') && $this->alcance->alcanzaColaborador($usuario, $prestamo->colaborador);
    }

    public function gestionar(User $usuario, Prestamo $prestamo): bool
    {
        return $usuario->can('prestamos.autorizar')
            && $usuario->colaborador_id !== $prestamo->colaborador_id
            && $this->alcance->alcanzaColaborador($usuario, $prestamo->colaborador);
    }

    /**
     * Autorizar/rechazar la solicitud de préstamo (RH/Dirección con
     * prestamos.autorizar, dentro de su alcance, nunca la propia).
     */
    public function autorizarSolicitud(User $usuario, SolicitudInterna $solicitud): bool
    {
        $colaborador = $solicitud->personaSolicitante();

        return $usuario->can('prestamos.autorizar')
            && $colaborador !== null
            && $usuario->colaborador_id !== $colaborador->id
            && $this->alcance->alcanzaColaborador($usuario, $colaborador);
    }

    public function resguardar(User $usuario, Prestamo $prestamo): bool
    {
        return $usuario->can('prestamos.resguardar')
            && $usuario->colaborador_id !== $prestamo->colaborador_id
            && $this->alcance->alcanzaColaborador($usuario, $prestamo->colaborador);
    }
}
