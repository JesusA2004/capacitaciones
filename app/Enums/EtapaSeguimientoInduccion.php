<?php

namespace App\Enums;

/**
 * Etapa de la Inducción Mr. Lana de una persona en ingreso, como la ve RH
 * en «Presentar inducción» (seguimiento). Se deriva del onboarding y de la
 * evaluación oficial — ver SesionInduccionService::seguimiento().
 */
enum EtapaSeguimientoInduccion: string
{
    case PendientePresentar = 'pendiente_presentar';
    case PorContestar = 'por_contestar';
    case PorCalificar = 'por_calificar';
    case Reforzamiento = 'reforzamiento';
    case ReevaluacionPorContestar = 'reevaluacion_por_contestar';
    case Aprobada = 'aprobada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PendientePresentar => 'Pendiente de presentar',
            self::PorContestar => 'Presentada · por contestar',
            self::PorCalificar => 'Por calificar casos prácticos (D)',
            self::Reforzamiento => 'Reforzamiento',
            self::ReevaluacionPorContestar => 'Reevaluación · por contestar',
            self::Aprobada => 'Aprobada',
        };
    }

    public static function desde(?EstadoEvaluacionInduccion $estado, bool $contenidoVisto): self
    {
        return match ($estado) {
            null => $contenidoVisto ? self::PorContestar : self::PendientePresentar,
            EstadoEvaluacionInduccion::Disponible => self::PorContestar,
            EstadoEvaluacionInduccion::PendienteCalificacionRh => self::PorCalificar,
            EstadoEvaluacionInduccion::RequiereReforzamiento => self::Reforzamiento,
            EstadoEvaluacionInduccion::ReevaluacionHabilitada => self::ReevaluacionPorContestar,
            EstadoEvaluacionInduccion::Aprobada => self::Aprobada,
        };
    }
}
