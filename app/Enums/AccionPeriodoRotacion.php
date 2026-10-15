<?php

namespace App\Enums;

/** Qué le pasa a un mes de una sucursal al aplicar una carga de rotación. */
enum AccionPeriodoRotacion: string
{
    case Nuevo = 'nuevo';
    case Reemplaza = 'reemplaza';
    case SinCambios = 'sin_cambios';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Nuevo => 'Nuevo',
            self::Reemplaza => 'Reemplaza lo cargado',
            self::SinCambios => 'Sin cambios',
        };
    }
}
