<?php

namespace App\Enums;

enum EstadoContratoLaboral: string
{
    case Vigente = 'vigente';
    case Renovado = 'renovado';
    case Terminado = 'terminado';
    case Cancelado = 'cancelado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Vigente => 'Vigente',
            self::Renovado => 'Renovado',
            self::Terminado => 'Terminado',
            self::Cancelado => 'Cancelado',
        };
    }
}
