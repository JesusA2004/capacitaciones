<?php

namespace App\Enums;

/**
 * Segmento con meta mensual propia de rotación (PDF Etapa 1, pág. 17, R5).
 */
enum SegmentoRotacion: string
{
    case Sucursales = 'sucursales';
    case Corporativo = 'corporativo';
    case Global = 'global';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Sucursales => 'Sucursales (operativo)',
            self::Corporativo => 'Corporativo',
            self::Global => 'Global Mr. Lana',
        };
    }
}
