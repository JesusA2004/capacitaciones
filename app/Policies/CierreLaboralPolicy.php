<?php

namespace App\Policies;

use App\Models\CierreLaboral;
use App\Models\User;
use App\Policies\Concerns\ConSuperAdminOperativo;
use App\Services\AlcanceOrganizacionalService;
use App\Services\CicloLaboral\OrganizacionJerarquiaService;

/**
 * Autorización por objeto del cierre laboral. Las reglas de cada etapa
 * (jefe directo o RH inician la baja y se aplica de inmediato → documentos
 * → finiquito → registrar entrega → cierre; sin preautorización ni
 * autorización posterior de RH) las valida además
 * App\Services\CierreLaboral\CierreLaboralService — igual para web y API.
 */
class CierreLaboralPolicy
{
    use ConSuperAdminOperativo;

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

    /** Subir el aviso de término firmado (gerente o RH, permiso propio). */
    public function subirAviso(User $usuario, CierreLaboral $cierre): bool
    {
        return $usuario->can('cierres.aviso.subir') && $this->operar($usuario, $cierre);
    }

    /** Generar el aviso de término (documento legal: RH por defecto). */
    public function generarAviso(User $usuario, CierreLaboral $cierre): bool
    {
        return $this->con($usuario, $cierre, 'cierres.aviso.generar');
    }

    public function cancelar(User $usuario, CierreLaboral $cierre): bool
    {
        return $this->con($usuario, $cierre, 'cierres.cancelar');
    }

    /** Vista previa / generación del PDF del finiquito del cierre. */
    public function descargarFiniquito(User $usuario, CierreLaboral $cierre): bool
    {
        return $usuario->can('finiquitos.descargar') && $this->calcularFiniquito($usuario, $cierre);
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

    /**
     * Registrar la entrega del finiquito (cierra el cierre laboral): RH que
     * gestiona cierres y calcula finiquitos.
     */
    public function entregarFiniquito(User $usuario, CierreLaboral $cierre): bool
    {
        return $this->con($usuario, $cierre, 'cierres.gestionar') && $usuario->can('finiquitos.calcular');
    }

    private function con(User $usuario, CierreLaboral $cierre, string $permiso): bool
    {
        return $usuario->can($permiso)
            && $usuario->colaborador_id !== $cierre->colaborador_id
            && $this->alcance->alcanzaColaborador($usuario, $cierre->colaborador);
    }

    private function enAlcance(User $usuario, CierreLaboral $cierre): bool
    {
        if ($this->alcance->alcanzaColaboradorEnModulo($usuario, $cierre->colaborador, 'cierres')) {
            return true;
        }

        return $usuario->colaborador !== null && $this->jerarquia->estaEnCadenaDeMando($usuario->colaborador, $cierre->colaborador);
    }
}
