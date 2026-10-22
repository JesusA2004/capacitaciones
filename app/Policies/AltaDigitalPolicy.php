<?php

namespace App\Policies;

use App\Models\AltaDigital;
use App\Models\User;
use App\Policies\Concerns\ConSuperAdminOperativo;

class AltaDigitalPolicy
{
    use ConSuperAdminOperativo;

    public function viewAny(User $usuario): bool
    {
        return $usuario->can('altas.ver');
    }

    public function view(User $usuario, AltaDigital $alta): bool
    {
        return $usuario->can('altas.ver');
    }

    /**
     * Descargar los documentos y la firma del alta: ver el alta no basta
     * (el gerente revisa/aprueba el alta, pero no descarga documentos de la
     * persona); exige documentos.descargar, igual que el expediente.
     */
    public function descargarDocumento(User $usuario, AltaDigital $alta): bool
    {
        return $usuario->can('altas.ver') && $usuario->can('documentos.descargar');
    }

    public function create(User $usuario): bool
    {
        return $usuario->can('altas.crear');
    }

    public function enviar(User $usuario, AltaDigital $alta): bool
    {
        return $usuario->can('altas.enviar');
    }

    public function revisar(User $usuario, AltaDigital $alta): bool
    {
        return $usuario->can('altas.revisar');
    }

    public function aprobar(User $usuario, AltaDigital $alta): bool
    {
        return $usuario->can('altas.aprobar');
    }

    public function cancelar(User $usuario, AltaDigital $alta): bool
    {
        return $usuario->can('altas.cancelar');
    }
}
