<?php

namespace App\Enums;

/**
 * Escala de resultado de la evaluación de inducción (FO-RH, "Escala de
 * resultados"): 27-30 sobresaliente, 24-26 aprobado, menos de 24 requiere
 * reforzamiento. Config: induccion_evaluacion.escala.
 */
enum NivelEvaluacionInduccion: string
{
    case Sobresaliente = 'sobresaliente';
    case Aprobado = 'aprobado';
    case RequiereReforzamiento = 'requiere_reforzamiento';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Sobresaliente => 'Comprensión sobresaliente',
            self::Aprobado => 'Aprobado',
            self::RequiereReforzamiento => 'Requiere reforzamiento',
        };
    }

    public function aprobado(): bool
    {
        return $this !== self::RequiereReforzamiento;
    }

    public static function paraCalificacion(float $calificacion): self
    {
        return self::paraCalificacionCon($calificacion, 24, 27);
    }

    /**
     * Con los umbrales de la versión del formulario con la que se contestó
     * (FormularioEvaluacionService): mínima aprobatoria y sobresaliente.
     */
    public static function paraCalificacionCon(float $calificacion, float $aprobatoria, float $sobresaliente): self
    {
        return match (true) {
            $calificacion >= $sobresaliente => self::Sobresaliente,
            $calificacion >= $aprobatoria => self::Aprobado,
            default => self::RequiereReforzamiento,
        };
    }
}
