<?php

namespace App\Enums;

/**
 * Material de explicación de la Inducción Mr. Lana que el colaborador ve
 * ENTRE las hojas del PDF y su evaluación. Uno solo activo a la vez por
 * módulo institucional (audio XOR video — ver
 * App\Services\Onboarding\MaterialInduccionService):
 *
 *  - Audio: narración continua que acompaña las hojas (no bloquea la
 *    evaluación, solo la enriquece).
 *  - Video: obligatorio tras las hojas, con anti-adelanto respaldado por el
 *    servidor; BLOQUEA la evaluación hasta verse completo
 *    (App\Services\Onboarding\ReproduccionVideoInduccionService).
 */
enum TipoMaterialInduccion: string
{
    case Audio = 'audio';
    case Video = 'video';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Audio => 'Audio (narración)',
            self::Video => 'Video',
        };
    }

    /**
     * Tipo de recurso multimedia coherente con este material (para validar la
     * coherencia del RecursoMultimedia al activarlo).
     */
    public function tipoRecurso(): TipoRecursoMultimedia
    {
        return match ($this) {
            self::Audio => TipoRecursoMultimedia::Audio,
            self::Video => TipoRecursoMultimedia::Video,
        };
    }

    public static function desdeRecurso(TipoRecursoMultimedia $tipo): ?self
    {
        return match ($tipo) {
            TipoRecursoMultimedia::Audio => self::Audio,
            TipoRecursoMultimedia::Video => self::Video,
            default => null,
        };
    }

    /** El video bloquea la evaluación hasta verse completo; el audio no. */
    public function bloqueaEvaluacion(): bool
    {
        return $this === self::Video;
    }
}
