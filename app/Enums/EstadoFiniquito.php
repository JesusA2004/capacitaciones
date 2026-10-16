<?php

namespace App\Enums;

/**
 * Ciclo de vida del cálculo de finiquito de una baja de colaborador (ver
 * App\Services\Finiquitos\FiniquitoService): borrador -> revisado desbloquea
 * la aprobación de la solicitud de baja (App\Services\Solicitudes\
 * SolicitudesService::cambiarEstado()); aprobado se marca cuando la propia
 * solicitud de baja queda aprobada; firmado cuando RH sube el documento
 * firmado por el colaborador.
 */
enum EstadoFiniquito: string
{
    case Borrador = 'borrador';
    case Revisado = 'revisado';
    case Aprobado = 'aprobado';
    case Firmado = 'firmado';
    case Pagado = 'pagado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Revisado => 'Revisado',
            self::Aprobado => 'Aprobado',
            self::Firmado => 'Firmado',
            self::Pagado => 'Pagado',
        };
    }

    /**
     * Estados definitivos: una vez firmado (o pagado) el finiquito ya no se
     * recalcula, ajusta, revisa ni regenera — el documento firmado por el
     * colaborador es inmutable (ver FiniquitoService::asegurarNoFirmado()).
     */
    public function esFinal(): bool
    {
        return $this === self::Firmado || $this === self::Pagado;
    }

    /**
     * true mientras el finiquito todavía puede editarse (montos, conceptos,
     * ajustes, regeneración de PDF). Inverso de esFinal().
     */
    public function esModificable(): bool
    {
        return ! $this->esFinal();
    }
}
