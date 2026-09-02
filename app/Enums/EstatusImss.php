<?php

namespace App\Enums;

enum EstatusImss: string
{
    case ConImss = 'con_imss';
    case SinImss = 'sin_imss';
    case PendienteImss = 'pendiente_imss';

    public function etiqueta(): string
    {
        return match ($this) {
            self::ConImss => 'Con IMSS',
            self::SinImss => 'Sin IMSS',
            self::PendienteImss => 'Pendiente de IMSS',
        };
    }
}
