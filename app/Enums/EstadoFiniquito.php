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

    public function etiqueta(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Revisado => 'Revisado',
            self::Aprobado => 'Aprobado',
            self::Firmado => 'Firmado',
        };
    }
}
