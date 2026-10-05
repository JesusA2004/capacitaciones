<?php

namespace App\Enums;

/**
 * Cómo se acomoda un fondo en la hoja.
 */
enum AjusteFondo: string
{
    case Estirar = 'stretch';
    case Ajustar = 'contain';
    case Cubrir = 'cover';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Estirar => 'Llenar página (estirar)',
            self::Ajustar => 'Ajustar',
            self::Cubrir => 'Cubrir',
        };
    }
}
