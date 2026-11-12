<?php

namespace App\Enums;

/**
 * Estado de una carta compromiso de documentos pendientes
 * (App\Services\Expedientes\CartaCompromisoService).
 *
 * - pendiente_aprobacion: la pidió la propia persona (Mi expediente / app)
 *   y espera a que RH la apruebe (cartas_compromiso.aprobar). Todavía no da
 *   plazo ni levanta el bloqueo de la app; el plazo corre desde la
 *   aprobación.
 * - rechazada: RH no aprobó la solicitud (con motivo); se conserva como
 *   historial y la persona puede pedir otra.
 * - vigente: dentro del plazo y con documentos por entregar.
 * - cumplida: todos los documentos comprometidos ya se subieron (en
 *   revisión o aprobados; no hace falta la aprobación de RH).
 * - vencida: pasó la fecha límite y faltan documentos (la app queda
 *   limitada a subirlos).
 * - sustituida: se generó una carta nueva para la misma persona; la
 *   anterior se conserva como historial.
 */
enum EstadoCartaCompromiso: string
{
    case PendienteAprobacion = 'pendiente_aprobacion';
    case Rechazada = 'rechazada';
    case Vigente = 'vigente';
    case Cumplida = 'cumplida';
    case Vencida = 'vencida';
    case Sustituida = 'sustituida';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PendienteAprobacion => 'Pendiente de aprobación de RH',
            self::Rechazada => 'Rechazada por RH',
            self::Vigente => 'Vigente',
            self::Cumplida => 'Cumplida',
            self::Vencida => 'Vencida',
            self::Sustituida => 'Sustituida',
        };
    }

    /**
     * ¿Solicitud que RH todavía no aprueba (o rechazó)? No da plazo, no
     * levanta el bloqueo de la app y no se guarda su PDF en el expediente.
     */
    public function sinAprobar(): bool
    {
        return $this === self::PendienteAprobacion || $this === self::Rechazada;
    }
}
