<?php

namespace App\Enums;

/**
 * «CAUSAL DEL PERMISO ESPECIAL» del Formato de Permiso oficial. Solo estas
 * cinco; no se agregan causales fuera del formato.
 */
enum CausalPermisoEspecial: string
{
    case Paternidad = 'paternidad';
    case Luto = 'luto';
    case Lactancia = 'lactancia';
    case Cumpleanos = 'cumpleanos';
    case Productividad = 'productividad';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Paternidad => 'Paternidad',
            self::Luto => 'Luto',
            self::Lactancia => 'Lactancia',
            self::Cumpleanos => 'Cumpleaños',
            self::Productividad => 'Productividad',
        };
    }
}
