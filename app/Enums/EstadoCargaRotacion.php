<?php

namespace App\Enums;

/**
 * Ciclo de un Excel de rotación de personal: se analiza (vista previa con
 * hallazgos), RH confirma y se aplica, o lo descarta. Nada se escribe en
 * `rotacion_periodos` antes de aplicar.
 */
enum EstadoCargaRotacion: string
{
    case Analizada = 'analizada';
    case Aplicada = 'aplicada';
    case Descartada = 'descartada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Analizada => 'Por confirmar',
            self::Aplicada => 'Aplicada',
            self::Descartada => 'Descartada',
        };
    }
}
