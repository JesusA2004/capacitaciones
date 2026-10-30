<?php

namespace App\Enums;

/** Resultado de una cita realizada: entrevista (viable) o estudio (favorable). */
enum ResultadoAgendaEvento: string
{
    case Viable = 'viable';
    case NoViable = 'no_viable';
    case Favorable = 'favorable';
    case NoFavorable = 'no_favorable';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Viable => 'Viable',
            self::NoViable => 'No viable',
            self::Favorable => 'Favorable',
            self::NoFavorable => 'No favorable',
        };
    }
}
