<?php

namespace App\Enums;

/**
 * Qué puede hacer el colaborador con la Inducción Mr. Lana en «Mi espacio».
 * La inducción la PRESENTA Recursos Humanos: el colaborador solo ve el
 * contenido mientras está en una sesión «En curso» que lo incluye; después
 * contesta su evaluación. Antes y después el contenido no se le muestra.
 * Ver App\Services\Onboarding\AccesoInduccionColaboradorService.
 */
enum AccesoInduccionColaborador: string
{
    // Ninguna sesión lo incluye todavía (o ya se canceló).
    case PendientePresentacion = 'pendiente_presentacion';
    // RH lo eligió en una sesión que sigue «En curso»: puede ver el contenido.
    case EnSesion = 'en_sesion';
    // RH terminó y le envió la evaluación: toca contestarla.
    case EvaluacionPendiente = 'evaluacion_pendiente';
    // Ya contestó: RH califica o lo retroalimenta antes de reabrirla.
    case EnRevision = 'en_revision';
    case Completada = 'completada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PendientePresentacion => 'Por presentar',
            self::EnSesion => 'En sesión con Recursos Humanos',
            self::EvaluacionPendiente => 'Evaluación por contestar',
            self::EnRevision => 'En revisión de Recursos Humanos',
            self::Completada => 'Completada',
        };
    }

    public function mensaje(): string
    {
        return match ($this) {
            self::PendientePresentacion => 'Recursos Humanos te presentará la Inducción Mr. Lana. Podrás verla aquí en cuanto inicie la sesión en la que te incluyan.',
            self::EnSesion => 'Recursos Humanos te está presentando la Inducción Mr. Lana. Al terminar te enviará tu evaluación.',
            self::EvaluacionPendiente => 'Recursos Humanos ya te presentó la Inducción Mr. Lana. Ahora contesta tu evaluación.',
            self::EnRevision => 'Ya contestaste tu evaluación de inducción. Recursos Humanos la está revisando.',
            self::Completada => 'Ya completaste tu Inducción Mr. Lana.',
        };
    }

    /** Solo durante la sesión de RH se muestra el contenido. */
    public function puedeVerContenido(): bool
    {
        return $this === self::EnSesion;
    }
}
