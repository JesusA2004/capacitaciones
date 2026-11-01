<?php

namespace App\Enums;

/** Navegar: abre `href`. Dialogo: la página abre su propio diálogo con esa clave. */
enum ModoAccionFlujo: string
{
    case Navegar = 'navegar';
    case Dialogo = 'dialogo';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Navegar => 'Abre otra pantalla',
            self::Dialogo => 'Se atiende en esta pantalla',
        };
    }
}
