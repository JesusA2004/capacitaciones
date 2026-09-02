<?php

namespace App\Enums;

enum TipoPuesto: string
{
    case Comercial = 'comercial';
    case Administrativo = 'administrativo';
    case Operativo = 'operativo';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Comercial => 'Comercial',
            self::Administrativo => 'Administrativo',
            self::Operativo => 'Operativo',
            self::Otro => 'Otro',
        };
    }
}
