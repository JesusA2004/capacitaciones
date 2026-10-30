<?php

namespace App\Enums;

/** Qué se agenda en Reclutamiento → Agenda de entrevistas. */
enum TipoAgendaEvento: string
{
    case Entrevista = 'entrevista';
    case Socioeconomico = 'socioeconomico';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Entrevista => 'Entrevista',
            self::Socioeconomico => 'Estudio socioeconómico',
        };
    }
}
