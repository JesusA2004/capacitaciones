<?php

namespace App\Policies;

use App\Models\Colaborador;
use App\Models\User;
use App\Policies\Concerns\ConSuperAdminOperativo;
use App\Services\AlcanceOrganizacionalService;
use App\Services\CicloLaboral\OrganizacionJerarquiaService;

/**
 * Acciones del ciclo laboral sobre un colaborador (alta, activación,
 * consulta de jerarquía/alta, cierre, documentos, recibos, actas). Siempre
 * permiso Spatie + alcance organizacional (evita IDOR: cambiar el id en la
 * URL no da acceso a colaboradores fuera del alcance). Las acciones
 * administrativas nunca se ejecutan sobre uno mismo — salvo super_admin,
 * ver ConSuperAdminOperativo.
 */
class ColaboradorPolicy
{
    use ConSuperAdminOperativo;

    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    public function darDeAlta(User $usuario): bool
    {
        return $usuario->can('colaboradores.alta');
    }

    public function verAlta(User $usuario, Colaborador $colaborador): bool
    {
        return $this->esPropio($usuario, $colaborador)
            || (($usuario->can('colaboradores.alta') || $usuario->can('expedientes.ver_todos') || $usuario->can('expedientes.ver_sucursal'))
                && $this->alcance->alcanzaColaborador($usuario, $colaborador));
    }

    public function activar(User $usuario, Colaborador $colaborador): bool
    {
        return $this->administrativo($usuario, $colaborador, 'colaboradores.activar');
    }

    public function verJerarquia(User $usuario, Colaborador $colaborador): bool
    {
        return $this->esPropio($usuario, $colaborador)
            || (($usuario->can('organigrama.ver') || $usuario->can('rh.colaboradores.detalle')) && $this->alcance->alcanzaColaborador($usuario, $colaborador));
    }

    public function generarDocumento(User $usuario, Colaborador $colaborador): bool
    {
        return $this->administrativo($usuario, $colaborador, 'documentos_laborales.generar');
    }

    public function verDocumentosLaborales(User $usuario, Colaborador $colaborador): bool
    {
        return $this->esPropio($usuario, $colaborador)
            || ($usuario->can('documentos_laborales.ver') && $this->alcance->alcanzaColaborador($usuario, $colaborador));
    }

    public function verContratos(User $usuario, Colaborador $colaborador): bool
    {
        return $this->esPropio($usuario, $colaborador)
            || ($usuario->can('contratos.ver') && $this->alcance->alcanzaColaborador($usuario, $colaborador));
    }

    /**
     * RH (cierres.gestionar) o el jefe/gerente (cierres.solicitar) — el jefe
     * también por cadena de mando aunque no tenga alcance de sucursal. La
     * regla completa (causa, baja inmediata sin preautorización ni
     * autorización posterior de RH) vive en CierreLaboralService.
     */
    public function iniciarCierre(User $usuario, Colaborador $colaborador): bool
    {
        // RH con alcance; los demás (cierres.solicitar) solo como jefe en la
        // cadena de mando real de la persona — nunca por alcance de sucursal.
        if ($this->administrativo($usuario, $colaborador, 'cierres.gestionar')) {
            return true;
        }

        if ($this->esPropio($usuario, $colaborador)) {
            return false;
        }

        // Gerente con alcance sobre la persona en el módulo de Bajas (su
        // personal / sucursal), igual que BajaService::puedeSolicitarPara().
        if ($usuario->can('bajas.solicitar') && $this->alcance->alcanzaColaboradorEnModulo($usuario, $colaborador, 'bajas')) {
            return true;
        }

        return $usuario->can('cierres.solicitar')
            && $usuario->colaborador !== null
            && app(OrganizacionJerarquiaService::class)->estaEnCadenaDeMando($usuario->colaborador, $colaborador);
    }

    public function crearRecibo(User $usuario, Colaborador $colaborador): bool
    {
        return $this->administrativo($usuario, $colaborador, 'nomina.recibos.crear');
    }

    public function crearActa(User $usuario, Colaborador $colaborador): bool
    {
        return $this->administrativo($usuario, $colaborador, 'actas.crear');
    }

    /**
     * Organigrama: subir/bajar/mover a alguien de plaza (pasa por el
     * movimiento laboral + jefe derivado). Solo RH (organigrama.mover_personas) con
     * alcance, nunca sobre uno mismo (super_admin sí, ver trait).
     */
    public function moverEnOrganigrama(User $usuario, Colaborador $colaborador): bool
    {
        return $usuario->can('organigrama.ver') && $this->administrativo($usuario, $colaborador, 'organigrama.mover_personas');
    }

    /** Organigrama: clasificar a un Gerente de Sucursal en Q1 o Q3. */
    public function clasificarRegion(User $usuario, Colaborador $colaborador): bool
    {
        return $usuario->can('organigrama.ver') && $this->administrativo($usuario, $colaborador, 'organigrama.mover_personas');
    }

    private function esPropio(User $usuario, Colaborador $colaborador): bool
    {
        return $usuario->colaborador_id !== null && $usuario->colaborador_id === $colaborador->id;
    }

    private function administrativo(User $usuario, Colaborador $colaborador, string $permiso): bool
    {
        return $usuario->can($permiso)
            && ! $this->esPropio($usuario, $colaborador)
            && $this->alcance->alcanzaColaborador($usuario, $colaborador);
    }
}
