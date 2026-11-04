<?php

namespace App\Enums;

use App\Enums\Concerns\OpcionesConEtiqueta;

/**
 * Tenencia de la vivienda: un solo dato para la Solicitud («¿Domicilio propio o rentado?») y el Estudio socioeconómico.
 */
enum TenenciaVivienda: string
{
    use OpcionesConEtiqueta;

    case Propia = 'propia';
    case Renta = 'renta';
    case Prestada = 'prestada';
    case Hipotecada = 'hipotecada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Propia => 'Propia',
            self::Renta => 'Rentada',
            self::Prestada => 'Prestada',
            self::Hipotecada => 'Hipotecada',
        };
    }
}
