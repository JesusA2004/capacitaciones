<?php

namespace App\Enums;

use App\Enums\Concerns\OpcionesConEtiqueta;

/**
 * Servicios con los que cuenta la vivienda (Estudio socioeconómico). La casilla impresa «Adobe» del original se interpreta como Gas (errata del formato, confirmar con RH).
 */
enum ServicioVivienda: string
{
    use OpcionesConEtiqueta;

    case Agua = 'agua';
    case Luz = 'luz';
    case Drenaje = 'drenaje';
    case Gas = 'gas';
    case Telefono = 'telefono';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Agua => 'Agua',
            self::Luz => 'Luz',
            self::Drenaje => 'Drenaje',
            self::Gas => 'Gas',
            self::Telefono => 'Teléfono',
            self::Otro => 'Otro',
        };
    }
}
