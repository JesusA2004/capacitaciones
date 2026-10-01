<?php

namespace App\Policies;

use App\Models\CierreLaboral;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\CicloLaboral\OrganizacionJerarquiaService;

/**
 * Autorización por objeto del cierre laboral. Las reglas de cada etapa
 * (quién preautoriza, que RH autoriza, quién programa el pago) las valida
 * además App\Services\CierreLaboral\CierreLaboralService — igual para web y API.
 */
class CierreLaboralPolicy
{
    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly OrganizacionJerarquiaService $jerarquia,
    ) {}

    public function ver(User $usuario, CierreLaboral $cierre): bool
    {
        return ($usuario->can('cierres.ver') || $usuario->can('cierres.solicitar')) && $this->enAlcance($usuario, $cierre);
    }

    /**
     * Operación del jefe/gerente o de RH (aviso, cita, firma, pago).
     */
    public function operar(User $usuario, CierreLaboral $cierre): bool
    {
        return ($usuario->can('cierres.gestionar') || $usuario->can('cierres.solicitar'))
            && $usuario->colaborador_id !== $cierre->colaborador_id
            && $this->enAlcance($usuario, $cierre);
    }

    public function gestionar(User $usuario, CierreLaboral $cierre): bool
    {
        return $this->con($usuario, $cierre, 'cierres.gestionar');
    }

    public function ejecutarBaja(User $usuario, CierreLaboral $cierre): bool
    {
        return $this->con($usuario, $cierre, 'cierres.ejecutar_baja');
    }

    public function confirmarPago(User $usuario, CierreLaboral $cierre): bool
    {
        return $this->operar($usuario, $cierre);
    }

    public function calcularFiniquito(User $usuario, CierreLaboral $cierre): bool
    {
        return $this->con($usuario, $cierre, 'finiquitos.calcular');
    }

    public function revisarFiniquito(User $usuario, CierreLaboral $cierre): bool
    {
        return $this->con($usuario, $cierre, 'finiquitos.revisar');
    }

    private function con(User $usuario, CierreLaboral $cierre, string $permiso): bool
    {
        return $usuario->can($permiso)
            && $usuario->colaborador_id !== $cierre->colaborador_id
            && $this->alcance->alcanzaColaborador($usuario, $cierre->colaborador);
    }

    private function enAlcance(User $usuario, CierreLaboral $cierre): bool
    {
        if ($this->alcance->alcanzaColaborador($usuario, $cierre->colaborador)) {
            return true;
        }

        return $usuario->colaborador !== null && $this->jerarquia->estaEnCadenaDeMando($usuario->colaborador, $cierre->colaborador);
    }
}
