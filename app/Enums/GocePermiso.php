<?php

namespace App\Enums;

/**
 * «TIPO DE PERMISO» del Formato de Permiso oficial. Si es especial, la
 * causal es obligatoria (CausalPermisoEspecial).
 */
enum GocePermiso: string
{
    case ConGoce = 'con_goce';
    case SinGoce = 'sin_goce';
    case Especial = 'especial';

    public function etiqueta(): string
    {
        return match ($this) {
            self::ConGoce => 'Con goce de sueldo',
            self::SinGoce => 'Sin goce de sueldo',
            self::Especial => 'Permiso especial',
        };
    }
}
