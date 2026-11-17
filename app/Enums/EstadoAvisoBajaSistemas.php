<?php

namespace App\Enums;

/**
 * Estado del CORREO de baja a Sistemas. Que Sistemas ya haya dado de baja
 * los usuarios en las demás plataformas es otra cosa:
 * avisos_baja_sistemas.atendido_en.
 */
enum EstadoAvisoBajaSistemas: string
{
    case Pendiente = 'pendiente';
    case Enviado = 'enviado';
    case Fallido = 'fallido';

    /** El correo está desactivado (SISTEMAS_ALTA_CORREO_ACTIVO): el pendiente existe y se puede mandar después. */
    case SinEnviar = 'sin_enviar';

    /** La baja se canceló («No procede») o se revirtió antes de que saliera el correo: ya no se manda. */
    case Cancelado = 'cancelado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'En cola',
            self::Enviado => 'Enviado',
            self::Fallido => 'No se pudo enviar',
            self::SinEnviar => 'Correo no enviado',
            self::Cancelado => 'Baja cancelada',
        };
    }

    /** Todavía puede salir (cola, reintento o envío manual). */
    public function porEnviar(): bool
    {
        return in_array($this, [self::Pendiente, self::Fallido, self::SinEnviar], true);
    }
}
