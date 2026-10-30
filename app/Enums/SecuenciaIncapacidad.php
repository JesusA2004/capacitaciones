<?php

namespace App\Enums;

enum SecuenciaIncapacidad: string
{
    case Inicial = 'inicial';
    case Subsecuente = 'subsecuente';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Inicial => 'Inicial',
            self::Subsecuente => 'Subsecuente',
        };
    }
}
