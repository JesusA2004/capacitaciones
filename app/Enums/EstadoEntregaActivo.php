<?php

namespace App\Enums;

enum EstadoEntregaActivo: string
{
    case Entregado = 'entregado';
    case Devuelto = 'devuelto';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Entregado => 'Entregado',
            self::Devuelto => 'Devuelto',
        };
    }
}
