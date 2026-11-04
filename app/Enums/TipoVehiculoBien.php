<?php

namespace App\Enums;

use App\Enums\Concerns\OpcionesConEtiqueta;

/**
 * Vehículo que posee la persona (Estudio socioeconómico: «Relación de bienes muebles»).
 */
enum TipoVehiculoBien: string
{
    use OpcionesConEtiqueta;

    case Automovil = 'automovil';
    case Moto = 'moto';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Automovil => 'Vehículo (automóvil)',
            self::Moto => 'Moto',
            self::Otro => 'Otro',
        };
    }
}
