<?php

namespace App\Policies;

use App\Enums\AlcanceDatos;
use App\Enums\TipoAgendaEvento;
use App\Models\AgendaEvento;
use App\Models\User;
use App\Policies\Concerns\ConSuperAdminOperativo;
use App\Services\Organigrama\AutoridadJerarquicaService;
use App\Services\Permisos\AlcanceModuloService;
use App\Services\Reclutamiento\AprobadorEntrevistaService;
use Illuminate\Auth\Access\Response;

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
        private readonly AutoridadJerarquicaService $autoridad,
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

    /**
     * Reprogramar, cancelar y corregir notas modifican lo decidido: además del alcance,
     * nunca sobre lo que hizo un puesto superior. Registrar el resultado es el
     * siguiente paso de la cita, no una modificación, y no pasa por aquí.
     */
    public function gestionar(User $usuario, AgendaEvento $evento): bool|Response
    {
        if (! $this->agendarEn($usuario, $evento->sucursal_id) || ! $this->alcanzaCandidato($usuario, $evento)) {
            return false;
        }

        $autores = $evento->historial()->pluck('user_id')->push($evento->agendado_por, $evento->resultado_por)->filter()->unique();

        foreach ($autores as $autor) {
            if (! $this->autoridad->puedeModificarLoHechoPor($usuario, $this->autoridad->autorPorId((int) $autor))) {
                return Response::deny(AutoridadJerarquicaService::MENSAJE_PUESTO_SUPERIOR);
            }
        }

        return true;
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
