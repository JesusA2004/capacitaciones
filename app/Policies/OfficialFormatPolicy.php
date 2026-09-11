<?php

namespace App\Policies;

use App\Models\OfficialFormat;
use App\Models\User;

class OfficialFormatPolicy
{
    public function viewAny(User $usuario): bool
    {
        return $usuario->can('formatos_oficiales.ver');
    }

    public function view(User $usuario, OfficialFormat $formato): bool
    {
        return $usuario->can('formatos_oficiales.ver');
    }

    public function generar(User $usuario, OfficialFormat $formato): bool
    {
        return $usuario->can('formatos_oficiales.generar');
    }

    public function descargar(User $usuario, OfficialFormat $formato): bool
    {
        return $usuario->can('formatos_oficiales.descargar');
    }

    public function configurar(User $usuario, OfficialFormat $formato): bool
    {
        return $usuario->can('formatos_oficiales.configurar');
    }
}
