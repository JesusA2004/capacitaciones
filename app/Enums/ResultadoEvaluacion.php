<?php

namespace App\Enums;

enum ResultadoEvaluacion: string
{
    case Aprobado = 'aprobado';
    case NoAprobado = 'no_aprobado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Aprobado => 'Aprobado',
            self::NoAprobado => 'No aprobado',
        };
    }
}
