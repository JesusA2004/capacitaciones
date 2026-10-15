<?php

namespace App\Enums;

/**
 * Ciclo de una responsiva de equipo (App\Models\Responsiva):
 *
 *   generada → firmada (se subió el escaneo firmado)
 *   generada/firmada → devuelta (el colaborador regresó el equipo)
 *   generada/firmada → cancelada (se generó por error; queda como historial)
 */
enum EstadoResponsiva: string
{
    case Generada = 'generada';
    case Firmada = 'firmada';
    case Devuelta = 'devuelta';
    case Cancelada = 'cancelada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Generada => 'Pendiente de firma',
            self::Firmada => 'Firmada',
            self::Devuelta => 'Equipo devuelto',
            self::Cancelada => 'Cancelada',
        };
    }

    /** Sigue vigente: el colaborador tiene el equipo bajo su resguardo. */
    public function vigente(): bool
    {
        return $this === self::Generada || $this === self::Firmada;
    }
}
