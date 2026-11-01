<?php

namespace App\Enums;

/** Recorridos que muestra el panel «Siguiente paso». */
enum FlujoOperativo: string
{
    case Contratacion = 'contratacion';
    case Permanencia = 'permanencia';
    case Baja = 'baja';
    case Sueldo = 'sueldo';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Contratacion => 'Contratación',
            self::Permanencia => 'Expediente y nómina',
            self::Baja => 'Baja',
            self::Sueldo => 'Sueldo',
        };
    }
}
