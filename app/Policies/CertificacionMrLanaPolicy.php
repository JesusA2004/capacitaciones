<?php

namespace App\Policies;

use App\Models\Colaborador;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;

/**
 * Certificación Mr. Lana por formato oficial (RH-CC-R-001): solo quien
 * tiene `certificacion.gestionar` y SOLO para personas de su alcance
 * organizacional. Nadie gestiona su propia certificación (igual que nadie
 * edita sus propios datos de RH).
 */
class CertificacionMrLanaPolicy
{
    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    public function viewAny(User $usuario): bool
    {
        return $usuario->can('certificacion.gestionar');
    }

    public function gestionar(User $usuario, Colaborador $colaborador): bool
    {
        return $usuario->can('certificacion.gestionar')
            && $usuario->colaborador_id !== $colaborador->id
            && $this->alcance->alcanzaColaborador($usuario, $colaborador);
    }
}
