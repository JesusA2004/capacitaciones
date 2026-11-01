<?php

namespace App\Enums;

/** Lineal: hay un estado que avanza. Hub: no hay etapas, solo lo más urgente. */
enum ModoFlujo: string
{
    case Lineal = 'lineal';
    case Hub = 'hub';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Lineal => 'Proceso con etapas',
            self::Hub => 'Punto de consulta',
        };
    }
}
