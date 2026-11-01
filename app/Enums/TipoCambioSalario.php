<?php

namespace App\Enums;

/**
 * Qué acto representa un renglón de `historial_salarios`. `fuente` dice de
 * dónde vino; esto dice QUÉ es, porque cada acto tiene reglas distintas
 * (CompensacionService::cambiarSueldo()).
 */
enum TipoCambioSalario: string
{
    case PrimeraCaptura = 'primera_captura';
    case Aumento = 'aumento';
    case Correccion = 'correccion';
    case AjusteConVigencia = 'ajuste_con_vigencia';
    case Importacion = 'importacion';

    public function etiqueta(): string
    {
        return match ($this) {
            self::PrimeraCaptura => 'Primera captura',
            self::Aumento => 'Aumento',
            self::Correccion => 'Corrección de captura',
            self::AjusteConVigencia => 'Ajuste con vigencia',
            self::Importacion => 'Importación',
        };
    }

    /** El monto anterior o la vigencia afectan periodos ya transcurridos. */
    public function afectaPeriodosPasados(): bool
    {
        return $this === self::Correccion || $this === self::AjusteConVigencia;
    }
}
