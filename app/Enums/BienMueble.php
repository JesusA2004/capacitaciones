<?php

namespace App\Enums;

use App\Enums\Concerns\OpcionesConEtiqueta;

/**
 * «Demás bienes muebles» del Estudio socioeconómico (se marcan con palomita).
 */
enum BienMueble: string
{
    use OpcionesConEtiqueta;

    case Dvd = 'dvd';
    case Radio = 'radio';
    case Lavadora = 'lavadora';
    case Computadora = 'computadora';
    case Television = 'television';
    case Alhajas = 'alhajas';
    case Estufa = 'estufa';
    case Sala = 'sala';
    case Comedor = 'comedor';
    case Refrigerador = 'refrigerador';
    case Microondas = 'microondas';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Dvd => 'DVD',
            self::Radio => 'Radio',
            self::Lavadora => 'Lavadora',
            self::Computadora => 'Computadora',
            self::Television => 'Televisión',
            self::Alhajas => 'Alhajas',
            self::Estufa => 'Estufa',
            self::Sala => 'Sala',
            self::Comedor => 'Comedor',
            self::Refrigerador => 'Refrigerador',
            self::Microondas => 'Horno de microondas',
            self::Otro => 'Otro',
        };
    }
}
