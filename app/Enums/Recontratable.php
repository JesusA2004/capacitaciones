<?php

namespace App\Enums;

/**
 * ¿La persona es recontratable? (RN-09). En rescisión el valor por defecto
 * es «No» y solo RH lo cambia.
 */
enum Recontratable: string
{
    case Si = 'si';
    case No = 'no';
    case ConReservas = 'con_reservas';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Si => 'Sí',
            self::No => 'No',
            self::ConReservas => 'Con reservas',
        };
    }
}
