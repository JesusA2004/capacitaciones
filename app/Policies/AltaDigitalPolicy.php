<?php

namespace App\Policies;

use App\Models\AltaDigital;
use App\Models\User;

class AltaDigitalPolicy
{
    public function viewAny(User $usuario): bool
    {
        return $usuario->can('altas.ver');
    }

    public function view(User $usuario, AltaDigital $alta): bool
    {
        return $usuario->can('altas.ver');
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
