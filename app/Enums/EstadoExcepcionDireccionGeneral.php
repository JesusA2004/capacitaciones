<?php

namespace App\Enums;

/**
 * Solicitud de excepción a la regla canónica de renovación del periodo de
 * prueba (FO-RH-EPP-001, nota al pie: "toda excepción requiere autorización
 * escrita de Dirección General"). Solo Dirección General resuelve — nunca
 * Regional ni Dirección Comercial.
 */
enum EstadoExcepcionDireccionGeneral: string
{
    case Pendiente = 'pendiente';
    case Aprobada = 'aprobada';
    case Rechazada = 'rechazada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente de Dirección General',
            self::Aprobada => 'Aprobada por Dirección General',
            self::Rechazada => 'Rechazada por Dirección General',
        };
    }

    public function esResuelta(): bool
    {
        return $this !== self::Pendiente;
    }
}
