<?php

namespace App\Enums;

/**
 * QA visual de UNA versión de documento maestro (ORIGINAL vs GENERADO
 * rasterizados página por página). Los valores se guardan en inglés porque
 * así se pidió el contrato de datos (visual_validation_status); la UI usa
 * etiqueta().
 */
enum EstadoValidacionVisual: string
{
    case Pendiente = 'pending';
    case Aprobada = 'passed';
    case Fallida = 'failed';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Falta validar diseño',
            self::Aprobada => 'Diseño validado',
            self::Fallida => 'Diseño no validado',
        };
    }
}
