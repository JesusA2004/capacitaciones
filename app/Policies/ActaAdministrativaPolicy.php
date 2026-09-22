<?php

namespace App\Policies;

use App\Enums\EstadoActa;
use App\Models\ActaAdministrativa;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;

/**
 * Actas: las consulta RH/Jurídico/gerencia dentro de su alcance (actas.ver);
 * las gestiona RH (actas.gestionar) o quien la creó mientras siga en
 * borrador. El propio colaborador no opera sobre sus actas.
 */
class ActaAdministrativaPolicy
{
    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    public function ver(User $usuario, ActaAdministrativa $acta): bool
    {
        return $usuario->can('actas.ver')
            && $usuario->colaborador_id !== $acta->colaborador_id
            && $this->alcance->alcanzaColaborador($usuario, $acta->colaborador);
    }

    public function gestionar(User $usuario, ActaAdministrativa $acta): bool
    {
        if ($usuario->colaborador_id === $acta->colaborador_id || ! $this->alcance->alcanzaColaborador($usuario, $acta->colaborador)) {
            return false;
        }

        return $usuario->can('actas.gestionar')
            || ($usuario->can('actas.crear') && $acta->creado_por === $usuario->id && $acta->estado === EstadoActa::Borrador);
    }
}
