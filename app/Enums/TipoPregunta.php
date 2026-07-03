<?php

namespace App\Enums;

enum TipoPregunta: string
{
    case OpcionUnica = 'opcion_unica';
    case OpcionMultiple = 'opcion_multiple';
    case VerdaderoFalso = 'verdadero_falso';
    case RespuestaCorta = 'respuesta_corta';

    public function etiqueta(): string
    {
        return match ($this) {
            self::OpcionUnica => 'Opción única',
            self::OpcionMultiple => 'Opción múltiple',
            self::VerdaderoFalso => 'Verdadero o falso',
            self::RespuestaCorta => 'Respuesta corta',
        };
    }

    /**
     * Las respuestas de este tipo se califican solas al enviarse; las de
     * respuesta corta requieren revision manual (permiso respuestas.calificar).
     */
    public function seCalificaAutomaticamente(): bool
    {
        return $this !== self::RespuestaCorta;
    }
}
