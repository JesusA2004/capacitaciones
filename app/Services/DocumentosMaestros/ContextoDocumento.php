<?php

namespace App\Services\DocumentosMaestros;

use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\Prestamo;
use App\Models\SolicitudInterna;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Todo lo que un documento maestro puede necesitar saber del momento
 * operativo en que se genera: la persona, el registro de negocio que lo
 * origina (contrato, cierre, evaluación, solicitud, préstamo), quién lo
 * genera, la fecha del documento y los pocos datos que solo existen en ese
 * acto (testigos del acta, hora de la negativa…).
 */
final class ContextoDocumento
{
    /**
     * @param  array<string, string>  $manuales  Datos capturados en el acto (testigos, hora, lugar del acta…).
     * @param  bool  $prestamoDeEjemplo  El préstamo es de EJEMPLO (prueba de un master con alguien sin préstamo): sus datos se marcan como tales.
     * @param  bool  $paqueteContrato  Documento del paquete del CONTRATO (capacitación, confidencialidad, no competencia, indeterminado): todas sus fechas contractuales son la fecha de generación.
     */
    public function __construct(
        public readonly Colaborador $colaborador,
        public readonly ?ContratoLaboral $contrato = null,
        public readonly ?CierreLaboral $cierre = null,
        public readonly ?EvaluacionPeriodoPrueba $evaluacion = null,
        public readonly ?SolicitudInterna $solicitud = null,
        public readonly ?Prestamo $prestamo = null,
        public readonly ?User $actor = null,
        public readonly array $manuales = [],
        public readonly ?CarbonImmutable $fecha = null,
        public readonly bool $prestamoDeEjemplo = false,
        public readonly bool $paqueteContrato = false,
    ) {}

    public function fechaDocumento(): CarbonImmutable
    {
        return $this->fecha ?? CarbonImmutable::now('America/Mexico_City');
    }

    /**
     * @param  array<string, string>  $manuales
     */
    public function conManuales(array $manuales): self
    {
        return new self($this->colaborador, $this->contrato, $this->cierre, $this->evaluacion, $this->solicitud, $this->prestamo, $this->actor, [...$this->manuales, ...$manuales], $this->fecha, $this->prestamoDeEjemplo, $this->paqueteContrato);
    }

    /**
     * Mismo contexto con un préstamo (real, propuesto en memoria o de ejemplo).
     */
    public function conPrestamo(Prestamo $prestamo, bool $deEjemplo = false): self
    {
        return new self($this->colaborador, $this->contrato, $this->cierre, $this->evaluacion, $this->solicitud, $prestamo, $this->actor, $this->manuales, $this->fecha, $deEjemplo, $this->paqueteContrato);
    }
}
