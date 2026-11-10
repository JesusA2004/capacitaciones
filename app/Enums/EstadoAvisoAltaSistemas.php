<?php

namespace App\Enums;

/**
 * Estado del CORREO «Alta para asignar usuarios» a Sistemas
 * (App\Services\Notificaciones\AvisoAltaSistemasService). Que Sistemas ya
 * haya dado de alta a la persona en las demás plataformas es otra cosa:
 * avisos_alta_sistemas.atendido_en (bandeja «Pendientes de alta en sistemas»).
 */
enum EstadoAvisoAltaSistemas: string
{
    case Pendiente = 'pendiente';
    case Enviado = 'enviado';
    case Fallido = 'fallido';

    /**
     * El pendiente existe pero el correo no salió: el aviso por correo está
     * desactivado (SISTEMAS_ALTA_CORREO_ACTIVO) o el pendiente se creó con el
     * comando de regularización sin --enviar. Se puede mandar desde la bandeja.
     */
    case SinEnviar = 'sin_enviar';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'En cola',
            self::Enviado => 'Enviado',
            self::Fallido => 'No se pudo enviar',
            self::SinEnviar => 'Correo no enviado',
        };
    }
}
