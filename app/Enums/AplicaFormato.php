<?php

namespace App\Enums;

/**
 * Para quién se genera una plantilla oficial.
 */
enum AplicaFormato: string
{
    case Colaborador = 'colaborador';
    case Candidato = 'candidato';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Colaborador => 'Colaborador',
            self::Candidato => 'Candidato',
        };
    }
}
