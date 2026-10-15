<?php

namespace App\Enums;

/**
 * Estado de una sesión de presentación de la Inducción Mr. Lana — ver
 * App\Services\Onboarding\SesionInduccionService.
 */
enum EstadoSesionInduccion: string
{
    // RH eligió a los asistentes y está presentando.
    case EnCurso = 'en_curso';
    // RH terminó y presionó «Enviar evaluación»: la de cada asistente quedó habilitada y se le avisó.
    case Presentada = 'presentada';
    case Cancelada = 'cancelada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::EnCurso => 'En curso',
            self::Presentada => 'Evaluación enviada',
            self::Cancelada => 'Cancelada',
        };
    }
}
