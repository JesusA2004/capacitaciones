<?php

namespace App\Enums;

enum ResultadoReferencia: string
{
    case Positiva = 'positiva';
    case Negativa = 'negativa';
    case NoLocalizada = 'no_localizada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Positiva => 'Positiva',
            self::Negativa => 'Negativa',
            self::NoLocalizada => 'No se pudo localizar',
        };
    }
}
