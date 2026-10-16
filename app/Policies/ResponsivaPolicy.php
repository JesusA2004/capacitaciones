<?php

namespace App\Policies;

use App\Enums\EstadoResponsiva;
use App\Models\Colaborador;
use App\Models\Responsiva;
use App\Models\User;
use App\Policies\Concerns\ConSuperAdminOperativo;
use App\Services\AlcanceOrganizacionalService;

/**
 * Responsivas de equipo: solo las ven y generan el gerente (su sucursal o
 * región: su equipo y su subgerente) y RH, con el alcance del módulo
 * «Responsivas» de Roles y permisos. Nunca sobre uno mismo: nadie se
 * entrega equipo a sí mismo ni consulta sus propias responsivas por aquí
 * (el colaborador no las ve en esta iteración).
 */
class ResponsivaPolicy
{
    use ConSuperAdminOperativo;

    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    /** Listar las responsivas de un colaborador. */
    public function verDe(User $usuario, Colaborador $colaborador): bool
    {
        return $usuario->can('responsivas.ver') && $this->alcanza($usuario, $colaborador);
    }

    /** Generar una responsiva nueva para un colaborador. */
    public function generar(User $usuario, Colaborador $colaborador): bool
    {
        return $usuario->can('responsivas.generar') && $this->alcanza($usuario, $colaborador);
    }

    public function ver(User $usuario, Responsiva $responsiva): bool
    {
        return $this->verDe($usuario, $responsiva->colaborador);
    }

    /** Marcar firmada físicamente o registrar la devolución del equipo. */
    public function operar(User $usuario, Responsiva $responsiva): bool
    {
        return $responsiva->estado->vigente() && $this->generar($usuario, $responsiva->colaborador);
    }

    /**
     * Cancelar: RH (responsivas.cancelar) o quien la capturó mientras siga
     * pendiente de firma (se equivocó al capturarla). «Quien la capturó» es
     * registrado_por (quien la generó en PEOPLE), no entregado_por (el
     * responsable de entrega, que pudo ser otra persona). registrado_por es
     * nullable: si es null, ese brazo nunca aplica.
     */
    public function cancelar(User $usuario, Responsiva $responsiva): bool
    {
        if (! $responsiva->estado->vigente() || ! $this->alcanza($usuario, $responsiva->colaborador)) {
            return false;
        }

        return $usuario->can('responsivas.cancelar')
            || ($usuario->can('responsivas.generar') && $responsiva->registrado_por === $usuario->id && $responsiva->estado === EstadoResponsiva::Generada);
    }

    private function alcanza(User $usuario, Colaborador $colaborador): bool
    {
        return $usuario->colaborador_id !== $colaborador->id
            && $this->alcance->alcanzaColaboradorEnModulo($usuario, $colaborador, 'responsivas');
    }
}
