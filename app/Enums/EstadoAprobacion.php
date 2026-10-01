<?php

namespace App\Enums;

enum EstadoAprobacion: string
{
    case Pendiente = 'pendiente';
    case Aprobado = 'aprobado';
    case Rechazado = 'rechazado';
    case Devuelto = 'devuelto';
    // La etapa no aplica (p. ej. la persona no tiene superior operativo en el
    // organigrama): queda registrada con su motivo, nunca se salta en silencio.
    case Omitido = 'omitido';
    // El proceso se canceló antes de decidir esta etapa.
    case Cancelado = 'cancelado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Aprobado => 'Aprobado',
            self::Rechazado => 'Rechazado',
            self::Devuelto => 'Devuelto para corrección',
            self::Omitido => 'No aplica',
            self::Cancelado => 'Cancelado',
        };
    }
}
