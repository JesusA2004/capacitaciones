<?php

namespace App\Enums;

enum EstadoAsistencia: string
{
    case Pendiente = 'pendiente';
    case Presente = 'presente';
    case Ausente = 'ausente';
    case Tarde = 'tarde';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Presente => 'Presente',
            self::Ausente => 'Ausente',
            self::Tarde => 'Tarde',
        };
    }
}
