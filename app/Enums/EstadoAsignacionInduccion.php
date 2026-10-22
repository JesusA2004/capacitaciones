<?php

namespace App\Enums;

/**
 * Cómo va la Inducción Mr. Lana asignada a una persona, como la ve RH en
 * «Inducción institucional» (asignaciones) — ver
 * App\Services\Onboarding\ProgresoInduccionService::resumen().
 */
enum EstadoAsignacionInduccion: string
{
    case SinAsignar = 'sin_asignar';
    case Retirada = 'retirada';
    case Asignada = 'asignada';
    case Viendo = 'viendo';
    case MaterialVisto = 'material_visto';
    case EvaluacionPendiente = 'evaluacion_pendiente';
    case EnRevision = 'en_revision';
    case Reforzamiento = 'reforzamiento';
    case Aprobada = 'aprobada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::SinAsignar => 'Sin asignar',
            self::Retirada => 'Asignación retirada',
            self::Asignada => 'Asignada · sin empezar',
            self::Viendo => 'Viendo la inducción',
            self::MaterialVisto => 'Contenido visto',
            self::EvaluacionPendiente => 'Evaluación pendiente',
            self::EnRevision => 'En revisión de RH',
            self::Reforzamiento => 'Reforzamiento',
            self::Aprobada => 'Aprobada',
        };
    }

    /** Se puede retirar mientras la persona no ha contestado la evaluación. */
    public function permiteRetirar(): bool
    {
        return match ($this) {
            self::Asignada, self::Viendo, self::MaterialVisto, self::EvaluacionPendiente => true,
            default => false,
        };
    }
}
