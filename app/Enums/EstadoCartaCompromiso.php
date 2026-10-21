<?php

namespace App\Enums;

/**
 * Estado de una carta compromiso de documentos pendientes
 * (App\Services\Expedientes\CartaCompromisoService).
 *
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
    case Vigente = 'vigente';
    case Cumplida = 'cumplida';
    case Vencida = 'vencida';
    case Sustituida = 'sustituida';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Vigente => 'Vigente',
            self::Cumplida => 'Cumplida',
            self::Vencida => 'Vencida',
            self::Sustituida => 'Sustituida',
        };
    }
}
