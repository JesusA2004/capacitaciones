<?php

namespace App\Enums;

/**
 * Género del colaborador, para el KPI de composición de plantilla del
 * dashboard RH (hombres/mujeres). "sin_especificar" es una respuesta
 * válida — nunca se infiere ni se asigna por defecto.
 */
enum Genero: string
{
    case Masculino = 'masculino';
    case Femenino = 'femenino';
    case SinEspecificar = 'sin_especificar';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Masculino => 'Masculino',
            self::Femenino => 'Femenino',
            self::SinEspecificar => 'Sin especificar',
        };
    }
}
