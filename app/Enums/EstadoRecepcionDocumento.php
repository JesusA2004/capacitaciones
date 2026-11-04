<?php

namespace App\Enums;

/**
 * Estado de recepción del ORIGINAL físico de un renglón esperado
 * (App\Models\RecepcionDocumentalRenglon). Es independiente del documento
 * digital: un contrato puede estar generado y firmado en el sistema y su
 * original seguir sin llegar a RH.
 */
enum EstadoRecepcionDocumento: string
{
    case Pendiente = 'pendiente';
    case Recibido = 'recibido';
    case RecibidoConObservacion = 'recibido_con_observacion';
    case NoRecibido = 'no_recibido';
    case NoAplica = 'no_aplica';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Recibido => 'Recibido',
            self::RecibidoConObservacion => 'Recibido con observación',
            self::NoRecibido => 'No recibido',
            self::NoAplica => 'No aplica',
        };
    }

    /** El original físico ya está en manos de RH. */
    public function esRecibido(): bool
    {
        return $this === self::Recibido || $this === self::RecibidoConObservacion;
    }

    /** Cuenta como incidencia en las métricas del corte. */
    public function esIncidencia(): bool
    {
        return $this === self::RecibidoConObservacion || $this === self::NoRecibido;
    }

    /** Ya no se espera nada más de este renglón. */
    public function esResuelto(): bool
    {
        return $this->esRecibido() || $this === self::NoAplica;
    }

    /** Exige texto al marcarlo: observación o justificación. */
    public function exigeTexto(): bool
    {
        return $this === self::RecibidoConObservacion || $this === self::NoRecibido || $this === self::NoAplica;
    }
}
