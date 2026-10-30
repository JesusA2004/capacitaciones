<?php

namespace App\Policies;

use App\Models\Incapacidad;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;

/**
 * Incapacidades: información médica. Solo quien tiene el permiso del módulo y
 * alcanza a la persona (Roles y permisos → Incapacidades). Nadie opera sobre
 * la suya propia.
 */
class IncapacidadPolicy
{
    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    public function ver(User $usuario, Incapacidad $incapacidad): bool
    {
        return $usuario->can('incapacidades.ver') && $this->alcanza($usuario, $incapacidad);
    }

    public function corregir(User $usuario, Incapacidad $incapacidad): bool
    {
        return $usuario->can('incapacidades.corregir') && $this->alcanza($usuario, $incapacidad);
    }

    public function subirDocumento(User $usuario, Incapacidad $incapacidad): bool
    {
        return $usuario->can('incapacidades.registrar') && $this->alcanza($usuario, $incapacidad);
    }

    public function descargar(User $usuario, Incapacidad $incapacidad): bool
    {
        return $usuario->can('incapacidades.descargar') && $this->alcanza($usuario, $incapacidad);
    }

    private function alcanza(User $usuario, Incapacidad $incapacidad): bool
    {
        return $usuario->colaborador_id !== $incapacidad->colaborador_id
            && $this->alcance->alcanzaColaboradorEnModulo($usuario, $incapacidad->colaborador, 'incapacidades');
    }
}
