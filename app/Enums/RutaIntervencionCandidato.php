<?php

namespace App\Enums;

/**
 * A quién se le pide decidir una intervención (CLAUDE.md §11): se resuelve
 * por el grupo del puesto objetivo del candidato (Puesto::grupo_indicador),
 * nunca por texto del nombre del puesto.
 */
enum RutaIntervencionCandidato: string
{
    case Regional = 'regional';
    case DireccionComercial = 'direccion_comercial';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Regional => 'Gerencia Regional',
            self::DireccionComercial => 'Dirección Comercial',
        };
    }
}
