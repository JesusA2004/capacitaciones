<?php

namespace App\Enums;

/**
 * Estado del correo «Alta para asignar usuarios» a Sistemas
 * (App\Services\Notificaciones\AvisoAltaSistemasService).
 */
enum EstadoAvisoAltaSistemas: string
{
    case Pendiente = 'pendiente';
    case Enviado = 'enviado';
    case Fallido = 'fallido';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'En cola',
            self::Enviado => 'Enviado',
            self::Fallido => 'No se pudo enviar',
        };
    }
}
