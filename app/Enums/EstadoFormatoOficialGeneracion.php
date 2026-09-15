<?php

namespace App\Enums;

/**
 * Ciclo de vida de un PDF generado a partir de un OfficialFormat
 * (App\Models\OfficialFormatGeneration) cuando el tipo de solicitud exige
 * firma (TipoSolicitudInterna::formatoRequiereFirma(), ver
 * docs/FORMATOS_OFICIALES.md).
 */
enum EstadoFormatoOficialGeneracion: string
{
    case Generado = 'generado';
    case Firmado = 'firmado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Generado => 'Generado',
            self::Firmado => 'Firmado',
        };
    }
}
