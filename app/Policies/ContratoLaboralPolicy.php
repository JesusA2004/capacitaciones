<?php

namespace App\Policies;

use App\Models\ContratoLaboral;
use App\Models\User;
use App\Policies\Concerns\ConSuperAdminOperativo;
use App\Services\AlcanceOrganizacionalService;

class ContratoLaboralPolicy
{
    use ConSuperAdminOperativo;

    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    public function ver(User $usuario, ContratoLaboral $contrato): bool
    {
        if ($usuario->colaborador_id !== null && $usuario->colaborador_id === $contrato->colaborador_id) {
            return true;
        }

        return $usuario->can('contratos.ver') && $this->alcance->alcanzaColaboradorEnModulo($usuario, $contrato->colaborador, 'contratos');
    }

    public function generarDocumento(User $usuario, ContratoLaboral $contrato): bool
    {
        // Solo la acción de Contratos (RH por defecto); el general de
        // documentos laborales ya no genera contratos (GeneratedDocumentPolicy).
        return GeneratedDocumentPolicy::puedeGenerarContratos($usuario)
            && $usuario->colaborador_id !== $contrato->colaborador_id
            && $this->alcance->alcanzaColaboradorEnModulo($usuario, $contrato->colaborador, 'contratos');
    }
}
