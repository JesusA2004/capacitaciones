<?php

namespace App\Enums;

/** Quién agendó: lo decide el alcance real de la cuenta, nunca la pantalla usada. */
enum OrigenAgendaEvento: string
{
    case Reclutamiento = 'reclutamiento';
    case Gerente = 'gerente';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Reclutamiento => 'Agendada por Reclutamiento',
            self::Gerente => 'Agendada por Gerente',
        };
    }
}
