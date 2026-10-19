<?php

namespace App\Enums;

/**
 * Qué puede hacer el colaborador con la Inducción Mr. Lana en «Mi espacio».
 *
 * NUEVO FLUJO (2026): la inducción se le ASIGNA (aviso) al iniciar su
 * onboarding y la ve SOLO —hojas del PDF oficial + material de audio/video—
 * sin que Recursos Humanos la presente en vivo. Ya no depende de ninguna
 * «sesión en curso» (el flujo legacy quedó tras el flag
 * features.induccion_sesiones_legacy). El acceso al contenido depende solo de
 * tener un avance institucional ASIGNADO y aún no aprobado.
 * Ver App\Services\Onboarding\AccesoInduccionColaboradorService.
 */
enum AccesoInduccionColaborador: string
{
    // No tiene inducción institucional asignada todavía (o ya no aplica).
    case SinAsignar = 'sin_asignar';
    // Asignada y por ver: hojas del PDF + material (audio/video).
    case Disponible = 'disponible';
    // Ya vio el contenido y la evaluación quedó habilitada: toca contestarla.
    case EvaluacionPendiente = 'evaluacion_pendiente';
    // Ya contestó: RH califica o lo retroalimenta antes de reabrirla.
    case EnRevision = 'en_revision';
    case Completada = 'completada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::SinAsignar => 'Sin asignar',
            self::Disponible => 'Por ver',
            self::EvaluacionPendiente => 'Evaluación por contestar',
            self::EnRevision => 'En revisión de Recursos Humanos',
            self::Completada => 'Completada',
        };
    }

    public function mensaje(): string
    {
        return match ($this) {
            self::SinAsignar => 'Todavía no tienes asignada la Inducción Mr. Lana. Aparecerá aquí en cuanto inicie tu proceso de ingreso.',
            self::Disponible => 'Tienes tu Inducción Mr. Lana asignada: revisa las hojas y el material, y al terminar presentarás tu evaluación.',
            self::EvaluacionPendiente => 'Ya revisaste la Inducción Mr. Lana. Ahora contesta tu evaluación.',
            self::EnRevision => 'Ya contestaste tu evaluación de inducción. Recursos Humanos la está revisando.',
            self::Completada => 'Ya completaste tu Inducción Mr. Lana.',
        };
    }

    /** El contenido (hojas + material) se ve mientras está asignada y no aprobada. */
    public function puedeVerContenido(): bool
    {
        return match ($this) {
            self::Disponible, self::EvaluacionPendiente, self::EnRevision => true,
            self::SinAsignar, self::Completada => false,
        };
    }
}
