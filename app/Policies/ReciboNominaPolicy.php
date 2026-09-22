<?php

namespace App\Policies;

use App\Models\ReciboNomina;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;

/**
 * El colaborador únicamente consulta sus propios recibos internos; RH los
 * consulta dentro de su alcance con nomina.recibos.ver.
 */
class ReciboNominaPolicy
{
    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    public function ver(User $usuario, ReciboNomina $recibo): bool
    {
        if ($usuario->colaborador_id !== null && $usuario->colaborador_id === $recibo->colaborador_id) {
            return true;
        }

        $colaborador = $recibo->colaborador()->withTrashed()->first();

        return $usuario->can('nomina.recibos.ver') && $colaborador !== null && $this->alcance->alcanzaColaborador($usuario, $colaborador);
    }

    public function importar(User $usuario): bool
    {
        return $usuario->can('nomina.recibos.importar');
    }

    public function regenerar(User $usuario, ReciboNomina $recibo): bool
    {
        $colaborador = $recibo->colaborador()->withTrashed()->first();

        return $usuario->can('nomina.recibos.crear') && $colaborador !== null && $this->alcance->alcanzaColaborador($usuario, $colaborador);
    }
}
