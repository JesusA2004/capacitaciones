<?php

namespace App\Enums;

enum EstadoRectificacionNomina: string
{
    case Pendiente = 'pendiente';
    case Aplicada = 'aplicada';
    case Descartada = 'descartada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Aplicada => 'Aplicada',
            self::Descartada => 'Descartada',
        };
    }
}
