<?php

namespace App\Enums;

enum EstadoInvitacionIncorporacion: string
{
    case Activo = 'activo';
    case Usado = 'usado';
    case Vencido = 'vencido';
    case Revocado = 'revocado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Activo => 'Activo',
            self::Usado => 'Usado',
            self::Vencido => 'Vencido',
            self::Revocado => 'Revocado',
        };
    }
}
