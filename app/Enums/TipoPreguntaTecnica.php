<?php

namespace App\Enums;

/**
 * Tipos de pregunta del formulario técnico (como Google Forms). Los de
 * opción se califican solos contra la respuesta correcta; la escala da
 * puntos proporcionales; las abiertas no suman puntos (quedan como
 * evidencia).
 */
enum TipoPreguntaTecnica: string
{
    case OpcionMultiple = 'opcion_multiple';
    case Casillas = 'casillas';
    case VerdaderoFalso = 'verdadero_falso';
    case Escala = 'escala';
    case RespuestaCorta = 'respuesta_corta';
    case Parrafo = 'parrafo';

    public function etiqueta(): string
    {
        return match ($this) {
            self::OpcionMultiple => 'Opción múltiple',
            self::Casillas => 'Casillas (varias correctas)',
            self::VerdaderoFalso => 'Verdadero o falso',
            self::Escala => 'Escala lineal',
            self::RespuestaCorta => 'Respuesta corta',
            self::Parrafo => 'Párrafo',
        };
    }

    public function tieneOpciones(): bool
    {
        return in_array($this, [self::OpcionMultiple, self::Casillas], true);
    }

    public function esAbierta(): bool
    {
        return in_array($this, [self::RespuestaCorta, self::Parrafo], true);
    }
}
