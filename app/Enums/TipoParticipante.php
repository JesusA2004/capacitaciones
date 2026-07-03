<?php

namespace App\Enums;

enum TipoParticipante: string
{
    case Interno = 'interno';
    case Externo = 'externo';
    case Anonimo = 'anonimo';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Interno => 'Colaborador interno',
            self::Externo => 'Externo identificado',
            self::Anonimo => 'Anónimo',
        };
    }
}
