<?php

namespace App\Enums;

/**
 * Qué se celebra (docs/CELEBRACIONES.md). Ambos tipos comparten registro,
 * tarjeta, avisos y mensajes privados (App\Models\BirthdayGreeting).
 */
enum TipoCelebracion: string
{
    case Cumpleanos = 'cumpleanos';
    case AniversarioLaboral = 'aniversario_laboral';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Cumpleanos => 'Cumpleaños',
            self::AniversarioLaboral => 'Aniversario laboral',
        };
    }
}
