<?php

namespace App\Enums;

use App\Enums\Concerns\OpcionesConEtiqueta;

/**
 * Parentesco de un dependiente económico (Estudio socioeconómico).
 */
enum ParentescoDependiente: string
{
    use OpcionesConEtiqueta;

    case Hijo = 'hijo';
    case Conyuge = 'conyuge';
    case Padre = 'padre';
    case Madre = 'madre';
    case Hermano = 'hermano';
    case Abuelo = 'abuelo';
    case Nieto = 'nieto';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Hijo => 'Hijo(a)',
            self::Conyuge => 'Cónyuge',
            self::Padre => 'Padre',
            self::Madre => 'Madre',
            self::Hermano => 'Hermano(a)',
            self::Abuelo => 'Abuelo(a)',
            self::Nieto => 'Nieto(a)',
            self::Otro => 'Otro',
        };
    }
}
