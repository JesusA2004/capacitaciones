<?php

namespace App\Enums;

/**
 * Estilo del encabezado de un formulario de evaluación (como Google Forms):
 * franja de color arriba, encabezado lleno o degradado suave.
 */
enum EstiloEncabezadoFormulario: string
{
    case Franja = 'franja';
    case Solido = 'solido';
    case Degradado = 'degradado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Franja => 'Franja de color',
            self::Solido => 'Encabezado de color',
            self::Degradado => 'Degradado suave',
        };
    }
}
