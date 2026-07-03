<?php

namespace App\Enums;

enum EstadoSesionEnVivo: string
{
    case Programada = 'programada';
    case EnCurso = 'en_curso';
    case Finalizada = 'finalizada';
    case Cancelada = 'cancelada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Programada => 'Programada',
            self::EnCurso => 'En curso',
            self::Finalizada => 'Finalizada',
            self::Cancelada => 'Cancelada',
        };
    }
}
