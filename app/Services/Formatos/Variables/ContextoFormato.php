<?php

namespace App\Services\Formatos\Variables;

use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\FiniquitoCalculo;
use App\Models\Prestamo;
use App\Models\SolicitudInterna;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Todo lo que el sistema sabe al generar un documento: la persona y, si
 * aplica, la solicitud / préstamo / contrato del que nace. El resolvedor
 * saca de aquí cada variable del catálogo; nadie pide al usuario un dato
 * que ya está en alguno de estos modelos.
 */
final class ContextoFormato
{
    public function __construct(
        public readonly Colaborador|Candidato|null $sujeto,
        public readonly ?SolicitudInterna $solicitud = null,
        public readonly ?Prestamo $prestamo = null,
        public readonly ?ContratoLaboral $contrato = null,
        public readonly ?User $generador = null,
        public readonly ?CarbonInterface $fecha = null,
        public readonly string $referencia = '',
        public readonly ?FiniquitoCalculo $finiquito = null,
    ) {}

    public function colaborador(): ?Colaborador
    {
        return $this->sujeto instanceof Colaborador ? $this->sujeto : null;
    }
}
