<?php

namespace App\Policies;

use App\Models\CierreLaboral;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;

class CierreLaboralPolicy
{
    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    public function ver(User $usuario, CierreLaboral $cierre): bool
    {
        return $usuario->can('cierres.ver') && $this->alcance->alcanzaColaborador($usuario, $cierre->colaborador);
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
        return $this->con($usuario, $cierre, 'finiquitos.confirmar_pago');
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
}
