<?php

namespace App\Enums;

/**
 * Ciclo de una evaluación técnica generada: pendiente (por contestar; se
 * muestra «vencida» si pasó su fecha límite) → contestada (con calificación
 * y aprobado/no aprobado). Cancelada: el colaborador se dio de baja o cambió
 * de puesto antes de contestarla — se conserva en su historial.
 */
enum EstadoEvaluacionTecnica: string
{
    case Pendiente = 'pendiente';
    case Contestada = 'contestada';
    case Cancelada = 'cancelada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Contestada => 'Contestada',
            self::Cancelada => 'Cancelada',
        };
    }
}
