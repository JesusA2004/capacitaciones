<?php

namespace App\Policies;

use App\Enums\EstadoReciboNomina;
use App\Models\ReciboNomina;
use App\Models\User;
use App\Policies\Concerns\ConSuperAdminOperativo;
use App\Services\AlcanceOrganizacionalService;

/**
 * El colaborador únicamente consulta sus propios recibos internos; RH los
 * consulta dentro de su alcance con nomina.recibos.ver.
 */
class ReciboNominaPolicy
{
    use ConSuperAdminOperativo;

    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    public function ver(User $usuario, ReciboNomina $recibo): bool
    {
        // Su propio recibo, solo ya emitido (el borrador es trabajo de RH) y
        // solo si el autoservicio de recibos está encendido (por defecto se
        // entrega impreso: config('features.recibos_colaborador')).
        if (config('features.recibos_colaborador') && $usuario->colaborador_id !== null && $usuario->colaborador_id === $recibo->colaborador_id && $recibo->estado === EstadoReciboNomina::Emitido) {
            return true;
        }

        $colaborador = $recibo->colaborador()->withTrashed()->first();

        return $usuario->can('nomina.recibos.ver') && $colaborador !== null && $this->alcance->alcanzaColaboradorEnModulo($usuario, $colaborador, 'recibos');
    }

    /**
     * Ver / descargar el PDF del recibo (vista previa del borrador o el
     * emitido): su propio recibo con las mismas reglas de ver(); el de otros,
     * con nomina.recibos.descargar dentro del alcance.
     */
    public function descargar(User $usuario, ReciboNomina $recibo): bool
    {
        if ($usuario->colaborador_id !== null && $usuario->colaborador_id === $recibo->colaborador_id) {
            return $this->ver($usuario, $recibo);
        }

        return $usuario->can('nomina.recibos.descargar') && $this->ver($usuario, $recibo);
    }

    /** Emitir un recibo (lo publica y congela su PDF). */
    public function emitir(User $usuario, ReciboNomina $recibo): bool
    {
        $colaborador = $recibo->colaborador()->withTrashed()->first();

        return $usuario->can('nomina.recibos.emitir') && $colaborador !== null && $this->alcance->alcanzaColaborador($usuario, $colaborador);
    }

    public function importar(User $usuario): bool
    {
        return $usuario->can('nomina.recibos.importar');
    }

    /** Ajustar conceptos o emitir: quien crea recibos, dentro de su alcance. */
    public function editar(User $usuario, ReciboNomina $recibo): bool
    {
        return $this->regenerar($usuario, $recibo);
    }

    public function regenerar(User $usuario, ReciboNomina $recibo): bool
    {
        $colaborador = $recibo->colaborador()->withTrashed()->first();

        return $usuario->can('nomina.recibos.crear') && $colaborador !== null && $this->alcance->alcanzaColaborador($usuario, $colaborador);
    }
}
