<?php

namespace App\Enums;

use App\Enums\Concerns\OpcionesConEtiqueta;

/**
 * Material de las paredes de la vivienda (Estudio socioeconómico).
 */
enum MaterialParedes: string
{
    use OpcionesConEtiqueta;

    case Concreto = 'concreto';
    case Lamina = 'lamina';
    case Adobe = 'adobe';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Concreto => 'Concreto',
            self::Lamina => 'Lámina',
            self::Adobe => 'Adobe',
            self::Otro => 'Otro',
        };
    }
}
