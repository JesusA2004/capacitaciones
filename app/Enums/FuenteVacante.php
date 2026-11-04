<?php

namespace App\Enums;

use App\Enums\Concerns\OpcionesConEtiqueta;

/**
 * «¿Cómo supiste de la vacante?» con las opciones exactas de la Solicitud de empleo.
 */
enum FuenteVacante: string
{
    use OpcionesConEtiqueta;

    case RedesSociales = 'redes_sociales';
    case Recomendacion = 'recomendacion';
    case Sucursal = 'sucursal';
    case BolsaTrabajo = 'bolsa_trabajo';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::RedesSociales => 'Redes sociales',
            self::Recomendacion => 'Recomendación',
            self::Sucursal => 'Sucursal',
            self::BolsaTrabajo => 'Bolsa de trabajo',
            self::Otro => 'Otro',
        };
    }
}
