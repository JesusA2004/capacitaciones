<?php

namespace App\Enums;

enum EstadoIntentoCuestionario: string
{
    case EnProgreso = 'en_progreso';
    case Enviado = 'enviado';
    case Calificado = 'calificado';
    case Expirado = 'expirado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::EnProgreso => 'En progreso',
            self::Enviado => 'Enviado, pendiente de calificación',
            self::Calificado => 'Calificado',
            self::Expirado => 'Expirado sin enviar',
        };
    }
}
