<?php

namespace App\Enums;

/**
 * Quién contesta una evaluación técnica («Seguimiento N»): el propio
 * colaborador (como examen) o su jefe directo, que SIEMPRE sale del
 * organigrama (colaboradores.jefe_id), nunca se captura a mano.
 */
enum QuienContestaEvaluacionTecnica: string
{
    case Colaborador = 'colaborador';
    case JefeDirecto = 'jefe_directo';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Colaborador => 'El colaborador (examen)',
            self::JefeDirecto => 'Su jefe directo (evaluador)',
        };
    }

    public function corta(): string
    {
        return match ($this) {
            self::Colaborador => 'Colaborador',
            self::JefeDirecto => 'Jefe directo',
        };
    }
}
