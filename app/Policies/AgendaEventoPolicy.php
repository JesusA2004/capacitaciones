<?php

namespace App\Policies;

use App\Enums\AlcanceDatos;
use App\Enums\TipoAgendaEvento;
use App\Models\AgendaEvento;
use App\Models\User;
use App\Policies\Concerns\ConSuperAdminOperativo;
use App\Services\Permisos\AlcanceModuloService;
use App\Services\Reclutamiento\AprobadorEntrevistaService;

/**
 * Agenda de reclutamiento: permiso de la acción + alcance del módulo sobre la
 * sucursal. Web y API usan la misma regla. Registrar el resultado exige además
 * el permiso del paso del candidato, que valida CandidatoWorkflowService.
 */
class AgendaEventoPolicy
{
    use ConSuperAdminOperativo;

    public function __construct(
        private readonly AlcanceModuloService $alcance,
        private readonly AprobadorEntrevistaService $aprobadorEntrevista,
    ) {}

    public function viewAny(User $usuario): bool
    {
        return $usuario->can('agenda.ver');
    }

    public function view(User $usuario, AgendaEvento $evento): bool
    {
        return $usuario->can('agenda.ver') && $this->alcanzaSucursal($usuario, $evento->sucursal_id) && $this->alcanzaCandidato($usuario, $evento);
    }

    public function agendarEn(User $usuario, int $sucursalId): bool
    {
        return $usuario->can('agenda.agendar') && $this->alcanzaSucursal($usuario, $sucursalId);
    }

    public function gestionar(User $usuario, AgendaEvento $evento): bool
    {
        return $this->agendarEn($usuario, $evento->sucursal_id);
    }

    public function registrarResultado(User $usuario, AgendaEvento $evento): bool
    {
        if (! $usuario->can('agenda.registrar_resultado') || ! $this->alcanzaSucursal($usuario, $evento->sucursal_id) || ! $this->alcanzaCandidato($usuario, $evento)) {
            return false;
        }

        // La entrevista la aprueba quien corresponde al puesto objetivo.
        return $evento->tipo !== TipoAgendaEvento::Entrevista || $this->aprobadorEntrevista->puedeAprobar($usuario, $evento->candidato);
    }

    /** Teléfono, domicilio y CV del candidato. */
    public function verDatosCandidato(User $usuario, AgendaEvento $evento): bool
    {
        return $usuario->can('agenda.ver_datos_candidato') && $this->alcanzaSucursal($usuario, $evento->sucursal_id) && $this->alcanzaCandidato($usuario, $evento);
    }

    /** Dirección Comercial solo ve las citas de los puestos que revisa. */
    private function alcanzaCandidato(User $usuario, AgendaEvento $evento): bool
    {
        return $this->aprobadorEntrevista->esVisiblePara($usuario, $evento->candidato);
    }

    private function alcanzaSucursal(User $usuario, int $sucursalId): bool
    {
        $nivel = $this->alcance->nivel($usuario, 'agenda');

        return $nivel === AlcanceDatos::Todos
            || $this->alcance->sucursalesPara($usuario, $nivel === AlcanceDatos::Region ? AlcanceDatos::Region : AlcanceDatos::Sucursal)->contains($sucursalId);
    }
}
