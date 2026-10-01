<?php

namespace App\Enums;

enum TipoModuloOnboarding: string
{
    case Institucional = 'institucional';
    case Puesto = 'puesto';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Institucional => 'Inducción institucional',
            self::Puesto => 'Inducción al puesto',
        };
    }
}
