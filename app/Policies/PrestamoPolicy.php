<?php

namespace App\Policies;

use App\Models\Prestamo;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Policies\Concerns\ConSuperAdminOperativo;
use App\Services\AlcanceOrganizacionalService;

class PrestamoPolicy
{
    use ConSuperAdminOperativo;

    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    public function ver(User $usuario, Prestamo $prestamo): bool
    {
        if ($usuario->colaborador_id !== null && $usuario->colaborador_id === $prestamo->colaborador_id) {
            return true;
        }

        return $usuario->can('prestamos.ver') && $this->alcance->alcanzaColaboradorEnModulo($usuario, $prestamo->colaborador, 'prestamos');
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

    /**
     * Confirmar la entrega del dinero y registrar abonos/ajustes del saldo
     * (desde el expediente): permiso propio `prestamos.gestionar`, alcance
     * sobre el expediente y nunca sobre el préstamo propio.
     */
    public function operarSaldo(User $usuario, Prestamo $prestamo): bool
    {
        return $usuario->can('prestamos.gestionar')
            && $usuario->colaborador_id !== $prestamo->colaborador_id
            && $this->alcance->puedeVerExpediente($usuario, $prestamo->colaborador);
    }

    public function resguardar(User $usuario, Prestamo $prestamo): bool
    {
        return $usuario->can('prestamos.resguardar')
            && $usuario->colaborador_id !== $prestamo->colaborador_id
            && $this->alcance->alcanzaColaborador($usuario, $prestamo->colaborador);
    }
}
