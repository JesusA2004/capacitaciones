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

    public function etiqueta(): string
    {
        return match ($this) {
            self::Creada => 'Creada',
            self::Enviada => 'Recibida',
            self::EnRevision => 'Pendiente de autorizar',
            self::Aprobada => 'Aprobada',
            self::Rechazada => 'Rechazada',
            self::RequiereCorreccion => 'Requiere corrección',
            self::Cancelada => 'Cancelada',
        };
    }

    /**
     * Estados finales: ya no aceptan más transiciones ni ediciones. No
     * existe un estado "cerrada": una solicitud aprobada ya es definitiva
     * (lo que pase después — finiquito, expediente — vive en su propio
     * proceso y queda en el historial).
     */
    public function esFinal(): bool
    {
        return match ($this) {
            self::Aprobada, self::Rechazada, self::Cancelada => true,
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

    /**
     * Mapa de transiciones válidas (docs/SOLICITUDES_UNIFICADAS.md): tanto el
     * tablero Kanban (drag and drop, SolicitudesService::moverEnTablero())
     * como las acciones de revisión del detalle pasan por
     * SolicitudesService::cambiarEstado(), que llama a este método antes de
     * escribir nada — el frontend nunca es la única autoridad.
     */
    public function puedeTransicionarA(self $destino): bool
    {
        if ($this === $destino) {
            return false;
        }

        if ($destino === self::Cancelada) {
            return $this->puedeCancelarse();
        }

        if ($this->esFinal()) {
            return false;
        }

        return match ($this) {
            self::Creada, self::Enviada => in_array($destino, [
                self::EnRevision, self::RequiereCorreccion, self::Aprobada, self::Rechazada,
            ], true),
            self::EnRevision => in_array($destino, [
                self::RequiereCorreccion, self::Aprobada, self::Rechazada,
            ], true),
            self::RequiereCorreccion => in_array($destino, [
                self::EnRevision, self::Rechazada,
            ], true),
            default => false,
        };
    }
}
