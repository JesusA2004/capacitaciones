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
        return match (true) {
            $calificacion >= 27 => self::Sobresaliente,
            $calificacion >= 24 => self::Aprobado,
            default => self::RequiereReforzamiento,
        };
    }
}
