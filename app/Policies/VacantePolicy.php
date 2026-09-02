<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vacante;
use App\Services\AlcanceOrganizacionalService;

class VacantePolicy
{
    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    public function viewAny(User $usuario): bool
    {
        return $usuario->can('vacantes.ver');
    }

    public function view(User $usuario, Vacante $vacante): bool
    {
        return $usuario->can('vacantes.ver') && $this->visiblePara($usuario, $vacante);
    }

    public function create(User $usuario): bool
    {
        return $usuario->can('vacantes.crear');
    }

    public function update(User $usuario, Vacante $vacante): bool
    {
        return $usuario->can('vacantes.editar') && $this->visiblePara($usuario, $vacante);
    }

    public function cambiarEstado(User $usuario, Vacante $vacante): bool
    {
        return $usuario->can('vacantes.cerrar') && $this->visiblePara($usuario, $vacante);
    }

    public function delete(User $usuario, Vacante $vacante): bool
    {
        return $usuario->can('vacantes.eliminar') && $this->visiblePara($usuario, $vacante);
    }

    private function visiblePara(User $usuario, Vacante $vacante): bool
    {
        if ($usuario->can('vacantes.ver_todos')) {
            return true;
        }

        if (! $usuario->can('vacantes.ver_sucursal')) {
            return false;
        }

        return $vacante->sucursal_id === null
            || $this->alcance->sucursalesVisiblesIds($usuario)->contains($vacante->sucursal_id);
    }
}
