<?php

namespace App\Enums;

enum TipoPregunta: string
{
    case OpcionUnica = 'opcion_unica';
    case OpcionMultiple = 'opcion_multiple';
    case VerdaderoFalso = 'verdadero_falso';
    case RespuestaCorta = 'respuesta_corta';
    case RespuestaLarga = 'respuesta_larga';
    case Escala = 'escala';
    case CargaArchivo = 'carga_archivo';

    public function etiqueta(): string
    {
        return match ($this) {
            self::OpcionUnica => 'Opción única',
            self::OpcionMultiple => 'Opción múltiple',
            self::VerdaderoFalso => 'Verdadero o falso',
            self::RespuestaCorta => 'Respuesta corta',
            self::RespuestaLarga => 'Respuesta larga',
            self::Escala => 'Escala',
            self::CargaArchivo => 'Carga de archivo',
        };
    }

    /**
     * Las respuestas de opcion/verdadero-falso se califican solas al
     * enviarse. Respuesta corta/larga, escala y carga de archivo requieren
     * siempre revision manual (permiso respuestas.calificar): no tienen un
     * criterio objetivo de "correcto" que el sistema pueda evaluar solo.
     */
    public function seCalificaAutomaticamente(): bool
    {
        return match ($this) {
            self::OpcionUnica, self::OpcionMultiple, self::VerdaderoFalso => true,
            self::RespuestaCorta, self::RespuestaLarga, self::Escala, self::CargaArchivo => false,
        };
    }

    /**
     * Tipos que se configuran con un catálogo de `opciones_pregunta` en el
     * constructor. Los demás usan su propia configuración (escala) o
     * ninguna (texto libre, archivo).
     */
    public function usaOpciones(): bool
    {
        return match ($this) {
            self::OpcionUnica, self::OpcionMultiple, self::VerdaderoFalso => true,
            default => false,
        };
    }
}
