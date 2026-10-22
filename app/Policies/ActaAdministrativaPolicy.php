<?php

namespace App\Policies;

use App\Enums\EstadoActa;
use App\Models\ActaAdministrativa;
use App\Models\User;
use App\Policies\Concerns\ConSuperAdminOperativo;
use App\Services\AlcanceOrganizacionalService;

/**
 * Actas: las consulta RH/Jurídico/gerencia dentro de su alcance (actas.ver).
 * Cada operación tiene su permiso propio (Roles y permisos → Actas):
 * editar, anexos, negativa/entrega, seguimiento, cerrar y descargar el
 * formato. RH necesita `actas.gestionar` (cualquier acta de su alcance) +
 * el permiso de la acción; quien la levantó (actas.crear) completa la suya
 * mientras siga en borrador y registra la entrega de su copia. Descargar
 * el formato es aparte (actas.descargar, por defecto RH/Jurídico/Dirección:
 * el gerente levanta y registra, no descarga). El propio colaborador no
 * opera sobre sus actas — salvo super_admin, ver ConSuperAdminOperativo.
 */
class ActaAdministrativaPolicy
{
    use ConSuperAdminOperativo;

    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    public function ver(User $usuario, ActaAdministrativa $acta): bool
    {
        return $usuario->can('actas.ver')
            && $usuario->colaborador_id !== $acta->colaborador_id
            && $this->alcance->alcanzaColaboradorEnModulo($usuario, $acta->colaborador, 'actas');
    }

    /**
     * ¿Opera esta acta? RH (actas.gestionar) sobre cualquiera de su alcance,
     * o quien la levantó mientras siga en borrador. No dice QUÉ acción: cada
     * una exige además su permiso (ver abajo).
     */
    public function gestionar(User $usuario, ActaAdministrativa $acta): bool
    {
        if (! $this->operable($usuario, $acta)) {
            return false;
        }

        return $usuario->can('actas.gestionar')
            || ($usuario->can('actas.crear') && $this->esSuBorrador($usuario, $acta));
    }

    public function editar(User $usuario, ActaAdministrativa $acta): bool
    {
        return $this->con($usuario, $acta, 'actas.editar');
    }

    public function subirAnexo(User $usuario, ActaAdministrativa $acta): bool
    {
        return $this->con($usuario, $acta, 'actas.anexos.subir');
    }

    /** Negativa a firmar. */
    public function registrarFirma(User $usuario, ActaAdministrativa $acta): bool
    {
        return $this->con($usuario, $acta, 'actas.registrar_firma');
    }

    /**
     * Constancia de entrega de copia: además de RH, quien levantó el acta
     * (la entrega ocurre después de imprimirla), en cualquier estado.
     */
    public function registrarEntrega(User $usuario, ActaAdministrativa $acta): bool
    {
        if (! $this->operable($usuario, $acta)) {
            return false;
        }

        return $acta->creado_por === $usuario->id
            || ($usuario->can('actas.gestionar') && $usuario->can('actas.registrar_firma'));
    }

    public function seguimiento(User $usuario, ActaAdministrativa $acta): bool
    {
        return $this->con($usuario, $acta, 'actas.seguimiento');
    }

    public function cerrar(User $usuario, ActaAdministrativa $acta): bool
    {
        return $this->con($usuario, $acta, 'actas.cerrar');
    }

    /** Vista previa del formato y descarga de anexos (consulta). */
    public function descargar(User $usuario, ActaAdministrativa $acta): bool
    {
        return $usuario->can('actas.descargar') && $this->ver($usuario, $acta);
    }

    /** Anexos (evidencias): con actas.descargar, o quien levantó el acta. */
    public function descargarAnexo(User $usuario, ActaAdministrativa $acta): bool
    {
        return $this->ver($usuario, $acta)
            && ($usuario->can('actas.descargar') || $acta->creado_por === $usuario->id);
    }

    /** Generar el formato oficial (escribe el documento): descarga + editar. */
    public function generarDocumento(User $usuario, ActaAdministrativa $acta): bool
    {
        return $usuario->can('actas.descargar') && $this->gestionar($usuario, $acta);
    }

    /**
     * RH: el permiso de la acción + actas.gestionar. Quien levantó el acta
     * completa SU borrador con solo actas.crear (levantar incluye terminar
     * de capturarla), como hasta ahora.
     */
    private function con(User $usuario, ActaAdministrativa $acta, string $permiso): bool
    {
        if (! $this->operable($usuario, $acta)) {
            return false;
        }

        if ($this->esSuBorrador($usuario, $acta) && ($usuario->can('actas.crear') || $usuario->can($permiso))) {
            return true;
        }

        return $usuario->can('actas.gestionar') && $usuario->can($permiso);
    }

    private function operable(User $usuario, ActaAdministrativa $acta): bool
    {
        return $usuario->colaborador_id !== $acta->colaborador_id && $this->alcance->alcanzaColaborador($usuario, $acta->colaborador);
    }

    private function esSuBorrador(User $usuario, ActaAdministrativa $acta): bool
    {
        return $acta->creado_por === $usuario->id && $acta->estado === EstadoActa::Borrador;
    }
}
