<?php

namespace App\Enums;

/** Resultado de una evaluación FORMAL de desempeño (no inducción ni periodo de prueba). */
enum NivelDesempeno: string
{
    case Sobresaliente = 'sobresaliente';
    case Satisfactorio = 'satisfactorio';
    case Bajo = 'bajo';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Sobresaliente => 'Sobresaliente',
            self::Satisfactorio => 'Satisfactorio',
            self::Bajo => 'Bajo',
        };
    }
}
