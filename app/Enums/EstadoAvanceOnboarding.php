<?php

namespace App\Enums;

enum EstadoAvanceOnboarding: string
{
    // Todavía no le toca: hay un módulo obligatorio anterior sin aprobar.
    case Bloqueado = 'bloqueado';
    case Disponible = 'disponible';
    // Último intento < calificación mínima: RH debe dar retroalimentación y
    // habilitar la reevaluación antes de un nuevo intento.
    case RequiereRefuerzo = 'requiere_refuerzo';
    case ReevaluacionHabilitada = 'reevaluacion_habilitada';
    case Aprobado = 'aprobado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Bloqueado => 'Bloqueado',
            self::Disponible => 'Disponible',
            self::RequiereRefuerzo => 'Requiere refuerzo de RH',
            self::ReevaluacionHabilitada => 'Reevaluación habilitada',
            self::Aprobado => 'Aprobado',
        };
    }

    public function permiteIntento(): bool
    {
        return $this === self::Disponible || $this === self::ReevaluacionHabilitada;
    }
}
