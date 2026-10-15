<?php

namespace App\Enums;

/**
 * Qué pasó con cada asistente al marcar presentada una sesión de inducción
 * (auditable: nunca se ignora a un asistente en silencio).
 */
enum ResultadoAsistenciaInduccion: string
{
    // Su evaluación oficial quedó habilitada y se le avisó que la conteste.
    case EvaluacionHabilitada = 'evaluacion_habilitada';
    // Ya tenía la evaluación abierta (disponible, por calificar o en
    // reforzamiento): no se crea otra; si puede contestarla se le recuerda.
    case EvaluacionEnCurso = 'evaluacion_en_curso';
    // Ya había aprobado su evaluación de inducción.
    case YaAprobada = 'ya_aprobada';
    // Aún no tiene proceso de ingreso (onboarding) abierto: la asistencia
    // queda registrada y se aplica sola cuando su onboarding inicie.
    case SinProcesoIngreso = 'sin_proceso_ingreso';

    public function etiqueta(): string
    {
        return match ($this) {
            self::EvaluacionHabilitada => 'Evaluación habilitada',
            self::EvaluacionEnCurso => 'Ya tenía su evaluación abierta',
            self::YaAprobada => 'Ya había aprobado la inducción',
            self::SinProcesoIngreso => 'Sin proceso de ingreso abierto (se aplicará al iniciarlo)',
        };
    }
}
