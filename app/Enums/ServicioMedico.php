<?php

namespace App\Enums;

use App\Enums\Concerns\OpcionesConEtiqueta;

/**
 * Servicio médico con que cuenta la persona antes de su alta (Estudio socioeconómico).
 */
enum ServicioMedico: string
{
    use OpcionesConEtiqueta;

    case Issste = 'issste';
    case Imss = 'imss';
    case SeguroPopular = 'seguro_popular';
    case Particular = 'particular';
    case Ninguno = 'ninguno';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Issste => 'ISSSTE',
            self::Imss => 'IMSS',
            self::SeguroPopular => 'Seguro Popular / IMSS-Bienestar',
            self::Particular => 'Particular',
            self::Ninguno => 'Ninguno',
        };
    }
}
