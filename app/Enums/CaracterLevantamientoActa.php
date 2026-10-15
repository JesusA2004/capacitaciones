<?php

namespace App\Enums;

/** Carácter con el que interviene quien levanta el acta (sección V.A). */
enum CaracterLevantamientoActa: string
{
    case RecursosHumanos = 'recursos_humanos';
    case Gerente = 'gerente';
    case JefeDirecto = 'jefe_directo';
    case ResponsableAutorizado = 'responsable_autorizado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::RecursosHumanos => 'Recursos Humanos',
            self::Gerente => 'Gerente',
            self::JefeDirecto => 'Jefe directo',
            self::ResponsableAutorizado => 'Responsable autorizado',
        };
    }
}
