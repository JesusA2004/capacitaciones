<?php

namespace App\Enums;

/**
 * Cómo captura fechas cada tipo de solicitud (TipoSolicitudInterna::modoFechas()).
 * El formulario web y la app muestran SOLO los campos de su modo; el
 * backend calcula lo demás (App\Services\Solicitudes\FechasSolicitudService):
 *
 * - duracion: fecha de inicio + número de días NATURALES consecutivos
 *   (incluye sábado y domingo). fecha_fin = inicio + (días − 1).
 * - dias_especificos: el colaborador elige días sueltos (vacaciones); el
 *   domingo no se puede elegir ni descuenta saldo.
 * - horario: un solo día con horario (salida temprano, llegada tarde…).
 * - fecha_unica: un solo día completo (permiso de cumpleaños).
 * - ninguna: no lleva fechas (constancia, préstamo, actualización…).
 */
enum ModoFechasSolicitud: string
{
    case Duracion = 'duracion';
    case DiasEspecificos = 'dias_especificos';
    case Horario = 'horario';
    case FechaUnica = 'fecha_unica';
    case Ninguna = 'ninguna';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Duracion => 'Fecha de inicio y número de días',
            self::DiasEspecificos => 'Selección de días',
            self::Horario => 'Un día con horario',
            self::FechaUnica => 'Un día',
            self::Ninguna => 'Sin fechas',
        };
    }
}
