<?php

namespace App\Enums;

/**
 * Resultado de cada filtro del reclutamiento (perfil, entrevista,
 * psicométricas, socioeconómico, referencias). Es la evaluación registrada
 * por el responsable — el sistema no decide por él.
 */
enum ResultadoEtapaCandidato: string
{
    case Viable = 'viable';
    case NoViable = 'no_viable';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Viable => 'Viable',
            self::NoViable => 'No viable',
        };
    }
}
