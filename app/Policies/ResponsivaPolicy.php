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

    /** Marcar firmada físicamente o registrar la devolución (cualquiera de las dos). */
    public function operar(User $usuario, Responsiva $responsiva): bool
    {
        return $this->registrarFirma($usuario, $responsiva) || $this->registrarDevolucion($usuario, $responsiva);
    }

    public function registrarFirma(User $usuario, Responsiva $responsiva): bool
    {
        return $usuario->can('responsivas.registrar_firma') && $this->vigenteEnAlcance($usuario, $responsiva);
    }

    public function registrarDevolucion(User $usuario, Responsiva $responsiva): bool
    {
        return $usuario->can('responsivas.registrar_devolucion') && $this->vigenteEnAlcance($usuario, $responsiva);
    }

    /** PDF de la responsiva y escaneo firmado histórico. */
    public function descargar(User $usuario, Responsiva $responsiva): bool
    {
        return $usuario->can('responsivas.descargar') && $this->ver($usuario, $responsiva);
    }

    /**
     * Nueva versión del PDF (con motivo; la anterior se conserva). Mismo permiso que
     * generar y con alcance. Sobre una firmada es una rectificación y exige además
     * documentos_laborales.revisar_firmado. Cancelada o devuelta, nunca.
     */
    public function regenerar(User $usuario, Responsiva $responsiva): bool
    {
        return $usuario->can('responsivas.generar')
            && ! in_array($responsiva->estado, [EstadoResponsiva::Cancelada, EstadoResponsiva::Devuelta], true)
            && ($responsiva->estado !== EstadoResponsiva::Firmada || $usuario->can('documentos_laborales.revisar_firmado'))
            && $this->alcanza($usuario, $responsiva->colaborador);
    }

    private function vigenteEnAlcance(User $usuario, Responsiva $responsiva): bool
    {
        return $responsiva->estado->vigente() && $this->alcanza($usuario, $responsiva->colaborador);
    }

    /**
     * Cancelar: RH (responsivas.cancelar) o quien la generó mientras siga
     * pendiente de firma (se equivocó al capturarla).
     */
    public function cancelar(User $usuario, Responsiva $responsiva): bool
    {
        if (! $responsiva->estado->vigente() || ! $this->alcanza($usuario, $responsiva->colaborador)) {
            return false;
        }

        return $usuario->can('responsivas.cancelar')
            || ($usuario->can('responsivas.generar') && $responsiva->entregado_por === $usuario->id && $responsiva->estado === EstadoResponsiva::Generada);
    }

    private function alcanza(User $usuario, Colaborador $colaborador): bool
    {
        return $usuario->colaborador_id !== $colaborador->id
            && $this->alcance->alcanzaColaboradorEnModulo($usuario, $colaborador, 'responsivas');
    }
}
