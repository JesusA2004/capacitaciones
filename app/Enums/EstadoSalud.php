<?php

namespace App\Enums;

use App\Enums\Concerns\OpcionesConEtiqueta;

/**
 * Cómo considera la persona su estado de salud (Solicitud de empleo).
 */
enum EstadoSalud: string
{
    use OpcionesConEtiqueta;

    case Bueno = 'bueno';
    case Regular = 'regular';
    case Malo = 'malo';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Bueno => 'Bueno',
            self::Regular => 'Regular',
            self::Malo => 'Malo',
        };
    }
}
