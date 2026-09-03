<?php

namespace App\Enums;

enum EstadoSolicitudInterna: string
{
    case Creada = 'creada';
    case Enviada = 'enviada';
    case EnRevision = 'en_revision';
    case Aprobada = 'aprobada';
    case Rechazada = 'rechazada';
    case RequiereCorreccion = 'requiere_correccion';
    case Cancelada = 'cancelada';
    case Cerrada = 'cerrada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Creada => 'Creada',
            self::Enviada => 'Enviada',
            self::EnRevision => 'En revisión',
            self::Aprobada => 'Aprobada',
            self::Rechazada => 'Rechazada',
            self::RequiereCorreccion => 'Requiere corrección',
            self::Cancelada => 'Cancelada',
            self::Cerrada => 'Cerrada',
        };
    }

    /**
     * Estados finales: ya no aceptan más transiciones ni ediciones.
     */
    public function esFinal(): bool
    {
        return match ($this) {
            self::Rechazada, self::Cancelada, self::Cerrada => true,
            default => false,
        };
    }

    /**
     * El colaborador solo puede cancelar mientras la solicitud sigue en
     * manos propias o apenas entrando a revisión.
     */
    public function puedeCancelarse(): bool
    {
        return match ($this) {
            self::Creada, self::Enviada, self::EnRevision, self::RequiereCorreccion => true,
            default => false,
        };
    }
}
