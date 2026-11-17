<?php

namespace App\Enums;

/**
 * Momento de la baja que origina el correo a Sistemas
 * (App\Services\Notificaciones\AvisoBajaSistemasService). Cada baja manda
 * a lo más uno de cada uno (clave única origen + evento).
 */
enum EventoAvisoBajaSistemas: string
{
    /** Se aplicó la baja y se bloquearon sus accesos: dar de baja sus usuarios en los demás sistemas. */
    case AccesosBloqueados = 'accesos_bloqueados';

    /** RH registró la entrega del finiquito y la baja quedó concluida: confirmar que no quede nada activo. */
    case BajaConcluida = 'baja_concluida';

    /**
     * La baja se canceló o revirtió DESPUÉS de que Sistemas recibió el
     * primer aviso: reactivar lo que se haya dado de baja.
     */
    case BajaCancelada = 'baja_cancelada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::AccesosBloqueados => 'Dar de baja accesos',
            self::BajaConcluida => 'Baja concluida',
            self::BajaCancelada => 'Baja cancelada',
        };
    }
}
