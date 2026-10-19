<?php

namespace App\Enums;

/**
 * Nivel de combustible al recibir el vehículo (sección 3 de la «RESPONSIVA DE
 * VEHÍCULO»): reserva, 1/4, 1/2, 3/4 o lleno.
 */
enum NivelCombustible: string
{
    case Reserva = 'reserva';
    case UnCuarto = 'un_cuarto';
    case Medio = 'medio';
    case TresCuartos = 'tres_cuartos';
    case Lleno = 'lleno';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Reserva => 'Reserva',
            self::UnCuarto => '1/4',
            self::Medio => '1/2',
            self::TresCuartos => '3/4',
            self::Lleno => 'Lleno',
        };
    }
}
