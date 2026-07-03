<?php

namespace App\Enums;

enum EstadoMultimedia: string
{
    case Cargando = 'cargando';
    case Pendiente = 'pendiente';
    case Procesando = 'procesando';
    case Disponible = 'disponible';
    case Error = 'error';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Cargando => 'Cargando',
            self::Pendiente => 'Pendiente de procesamiento',
            self::Procesando => 'Procesando',
            self::Disponible => 'Disponible',
            self::Error => 'Error',
        };
    }
}
