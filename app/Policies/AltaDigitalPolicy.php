<?php

namespace App\Policies;

use App\Models\AltaDigital;
use App\Models\User;
use App\Policies\Concerns\ConSuperAdminOperativo;
use App\Services\AlcanceOrganizacionalService;

/**
 * Alta digital: permiso de la acción + alcance de sucursal, el mismo
 * criterio del listado (AlcanceOrganizacionalService::limitarPorSucursal en
 * AltaDigitalController::queryFiltrada). Sin el alcance, un gerente de otra
 * sucursal abría por ID cualquier alta (CURP, RFC, NSS, domicilio, foto y la
 * liga pública con su token) y podía revisarla/aprobarla/cancelarla.
 */
class AltaDigitalPolicy
{
    use ConSuperAdminOperativo;

    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    public function viewAny(User $usuario): bool
    {
        return $usuario->can('altas.ver');
    }

    public function view(User $usuario, AltaDigital $alta): bool
    {
        return $usuario->can('altas.ver') && $this->enAlcance($usuario, $alta);
    }

    /**
     * Descargar los documentos y la firma del alta: ver el alta no basta
     * (el gerente revisa/aprueba el alta, pero no descarga documentos de la
     * persona); exige documentos.descargar, igual que el expediente.
     */
    public function descargarDocumento(User $usuario, AltaDigital $alta): bool
    {
        return $this->view($usuario, $alta) && $usuario->can('documentos.descargar');
    }

    public function create(User $usuario): bool
    {
        return $usuario->can('altas.crear');
    }

    public function enviar(User $usuario, AltaDigital $alta): bool
    {
        return $usuario->can('altas.enviar') && $this->enAlcance($usuario, $alta);
    }

    public function revisar(User $usuario, AltaDigital $alta): bool
    {
        return $usuario->can('altas.revisar') && $this->enAlcance($usuario, $alta);
    }

    public function aprobar(User $usuario, AltaDigital $alta): bool
    {
        return $usuario->can('altas.aprobar') && $this->enAlcance($usuario, $alta);
    }

    public function cancelar(User $usuario, AltaDigital $alta): bool
    {
        return $usuario->can('altas.cancelar') && $this->enAlcance($usuario, $alta);
    }

    /** Igual que el listado: alcance global, alta sin sucursal o una de sus sucursales. */
    private function enAlcance(User $usuario, AltaDigital $alta): bool
    {
        return $this->alcance->tieneAlcanceGlobal($usuario)
            || $alta->sucursal_id === null
            || $this->alcance->sucursalesVisiblesIds($usuario)->contains($alta->sucursal_id);
    }
}
