<?php

namespace App\Enums;

/**
 * Cómo se puede corregir un dato (config/correcciones.php): el modo efectivo
 * depende además del estado del registro (firmado, emitido, pagado…).
 */
enum ModoCorreccion: string
{
    case Directo = 'directo';
    case ConVigencia = 'con_vigencia';
    case SoloRectificacion = 'solo_rectificacion';
    case NuevaVersion = 'nueva_version';
    case NoCorregible = 'no_corregible';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Directo => 'Corrección directa',
            self::ConVigencia => 'Corrección con vigencia',
            self::SoloRectificacion => 'Solo por rectificación',
            self::NuevaVersion => 'Requiere nueva versión',
            self::NoCorregible => 'No se puede corregir',
        };
    }

    /** ¿Permite capturar un valor nuevo y aplicarlo desde el diálogo? */
    public function admiteCaptura(): bool
    {
        return $this === self::Directo || $this === self::ConVigencia || $this === self::SoloRectificacion;
    }
}
