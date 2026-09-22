<?php

namespace App\Policies;

use App\Models\ContratoLaboral;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;

class ContratoLaboralPolicy
{
    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    public function ver(User $usuario, ContratoLaboral $contrato): bool
    {
        if ($usuario->colaborador_id !== null && $usuario->colaborador_id === $contrato->colaborador_id) {
            return true;
        }

        return $usuario->can('contratos.ver') && $this->alcance->alcanzaColaborador($usuario, $contrato->colaborador);
    }

    public function generarDocumento(User $usuario, ContratoLaboral $contrato): bool
    {
        return $usuario->can('documentos_laborales.generar')
            && $usuario->colaborador_id !== $contrato->colaborador_id
            && $this->alcance->alcanzaColaborador($usuario, $contrato->colaborador);
    }
}
