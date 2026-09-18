<?php

namespace App\Policies;

use App\Enums\EstadoFiniquito;
use App\Models\FiniquitoCalculo;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;

/**
 * Autorización del cálculo de finiquito (ver
 * App\Services\Finiquitos\FiniquitoService). Mismo criterio de alcance
 * organizacional que SolicitudInternaPolicy: quien calcula/revisa un
 * finiquito debe poder ver al colaborador objetivo, no a cualquiera.
 */
class FiniquitoCalculoPolicy
{
    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    /**
     * Calcular por primera vez: se autoriza sobre la SolicitudInterna (el
     * cálculo todavía no existe), igual criterio que
     * SolicitudInternaPolicy::crearBaja().
     */
    public function calcular(User $usuario, SolicitudInterna $solicitud): bool
    {
        return $usuario->can('finiquitos.calcular')
            && $solicitud->colaboradorObjetivo !== null
            && $this->alcance->puedeVerUsuario($usuario, $solicitud->colaboradorObjetivo);
    }

    public function ver(User $usuario, FiniquitoCalculo $finiquito): bool
    {
        return $usuario->can('finiquitos.ver') && $this->alcance->puedeVerExpediente($usuario, $finiquito->colaborador);
    }

    /**
     * Recalcular / ajustar montos: solo mientras no esté firmado (una vez
     * firmado por el colaborador, el documento entregado no se debe volver
     * a mover — un ajuste posterior requeriría un finiquito complementario,
     * fuera de alcance de este módulo).
     */
    public function editarAjustes(User $usuario, FiniquitoCalculo $finiquito): bool
    {
        return $usuario->can('finiquitos.calcular')
            && $finiquito->estado !== EstadoFiniquito::Firmado
            && $this->alcance->puedeVerExpediente($usuario, $finiquito->colaborador);
    }

    public function revisar(User $usuario, FiniquitoCalculo $finiquito): bool
    {
        return $usuario->can('finiquitos.revisar')
            && $finiquito->estado === EstadoFiniquito::Borrador
            && $this->alcance->puedeVerExpediente($usuario, $finiquito->colaborador);
    }

    public function subirFirmado(User $usuario, FiniquitoCalculo $finiquito): bool
    {
        return $usuario->can('finiquitos.subir_firmado') && $this->alcance->puedeVerExpediente($usuario, $finiquito->colaborador);
    }
}
