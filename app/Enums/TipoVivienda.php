<?php

namespace App\Enums;

use App\Enums\Concerns\OpcionesConEtiqueta;

/**
 * Tipo de vivienda donde habita (Estudio socioeconómico).
 */
enum TipoVivienda: string
{
    use OpcionesConEtiqueta;

    case Casa = 'casa';
    case Departamento = 'departamento';
    case Cuarto = 'cuarto';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Casa => 'Casa',
            self::Departamento => 'Departamento',
            self::Cuarto => 'Cuarto',
        };
    }
}
