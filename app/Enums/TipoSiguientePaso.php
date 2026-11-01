<?php

namespace App\Enums;

/** Puedes actuar, le toca a alguien más, o no hay nada pendiente. */
enum TipoSiguientePaso: string
{
    case Accion = 'accion';
    case Espera = 'espera';
    case Terminado = 'terminado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Accion => 'Te toca',
            self::Espera => 'En espera',
            self::Terminado => 'Sin pendientes',
        };
    }
}
