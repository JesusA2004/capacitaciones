<?php

namespace App\Enums;

/**
 * De donde proviene un RecursoMultimedia. Determina si aparece en la
 * biblioteca administrativa general (ver RecursoMultimediaController@index)
 * o si es una evidencia privada asociada a una entrega/intento concreto,
 * visible solo mediante el flujo de revisión correspondiente.
 */
enum OrigenRecursoMultimedia: string
{
    case Biblioteca = 'biblioteca';
    case Curso = 'curso';
    case Actividad = 'actividad';
    case Cuestionario = 'cuestionario';
    case Sistema = 'sistema';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Biblioteca => 'Biblioteca multimedia',
            self::Curso => 'Curso',
            self::Actividad => 'Evidencia de actividad',
            self::Cuestionario => 'Evidencia de cuestionario',
            self::Sistema => 'Sistema',
        };
    }

    /**
     * Origenes que nunca deben listarse en la biblioteca administrativa
     * general porque son evidencias privadas de un colaborador concreto.
     */
    public function esPrivado(): bool
    {
        return match ($this) {
            self::Actividad, self::Cuestionario => true,
            default => false,
        };
    }
}
