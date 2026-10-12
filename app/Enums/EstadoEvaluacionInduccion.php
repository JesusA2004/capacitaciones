<?php

namespace App\Enums;

/**
 * Estado de la evaluación oficial de inducción (FO vigente, 30 puntos) de un
 * colaborador. Una sola fila por onboarding — el historial de presentaciones
 * vive en EvaluacionInduccionIntento (nunca se sobrescribe un intento). Ver
 * App\Services\Onboarding\EvaluacionInduccionService.
 */
enum EstadoEvaluacionInduccion: string
{
    // Habilitada, el colaborador todavía no la presenta.
    case Disponible = 'disponible';
    // Presentada: RH tiene pendiente calificar los casos prácticos (sección D).
    case PendienteCalificacionRh = 'pendiente_calificacion_rh';
    // Calificada con menos de 24/30: RH debe retroalimentar antes de reabrir.
    case RequiereReforzamiento = 'requiere_reforzamiento';
    case ReevaluacionHabilitada = 'reevaluacion_habilitada';
    case Aprobada = 'aprobada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Disponible => 'Disponible',
            self::PendienteCalificacionRh => 'Pendiente de calificar (RH)',
            self::RequiereReforzamiento => 'Requiere reforzamiento',
            self::ReevaluacionHabilitada => 'Reevaluación habilitada',
            self::Aprobada => 'Aprobada',
        };
    }

    public function permitePresentar(): bool
    {
        return $this === self::Disponible || $this === self::ReevaluacionHabilitada;
    }
}
