<?php

namespace App\Enums;

/**
 * Estado de revisión de un espacio del documento en el editor visual:
 * verde = confirmado por RH, amarillo = sugerencia del detector pendiente
 * de confirmar, rojo = zona detectada sin dato asignado. Las zonas que no
 * llevan dato (firma, huella) se pintan en gris sin importar el estado.
 */
enum EstadoCampoDocumento: string
{
    case Confirmado = 'confirmado';
    case Sugerido = 'sugerido';
    case SinAsignar = 'sin_asignar';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Confirmado => 'Confirmado',
            self::Sugerido => 'Sugerencia por confirmar',
            self::SinAsignar => 'Sin dato asignado',
        };
    }
}
