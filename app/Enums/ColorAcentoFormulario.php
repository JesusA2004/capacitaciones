<?php

namespace App\Enums;

/**
 * Colores de acento permitidos para el diseño de un formulario de
 * evaluación: SOLO tokens de la paleta pastel (resources/css/app.css,
 * --pastel-*), nunca un hex capturado por el usuario.
 */
enum ColorAcentoFormulario: string
{
    case Petroleo = 'petroleo';
    case Esmeralda = 'esmeralda';
    case Oro = 'oro';
    case Bronce = 'bronce';
    case Salvia = 'salvia';
    case Crema = 'crema';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Petroleo => 'Petróleo',
            self::Esmeralda => 'Esmeralda',
            self::Oro => 'Oro',
            self::Bronce => 'Bronce',
            self::Salvia => 'Salvia',
            self::Crema => 'Crema',
        };
    }
}
