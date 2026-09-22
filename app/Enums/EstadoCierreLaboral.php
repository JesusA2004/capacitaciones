<?php

namespace App\Enums;

/**
 * Avance del cierre laboral (App\Models\CierreLaboral). El orden refleja el
 * flujo: inicio → aviso/renuncia → finiquito → firmas → pago → baja →
 * expediente cerrado.
 */
enum EstadoCierreLaboral: string
{
    case Iniciado = 'iniciado';
    case AvisoRegistrado = 'aviso_registrado';
    case FiniquitoEnProceso = 'finiquito_en_proceso';
    case FiniquitoFirmado = 'finiquito_firmado';
    case Pagado = 'pagado';
    case BajaEjecutada = 'baja_ejecutada';
    case ExpedienteCerrado = 'expediente_cerrado';
    case Cancelado = 'cancelado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Iniciado => 'Iniciado',
            self::AvisoRegistrado => 'Aviso o renuncia registrada',
            self::FiniquitoEnProceso => 'Finiquito en proceso',
            self::FiniquitoFirmado => 'Finiquito firmado',
            self::Pagado => 'Pago confirmado',
            self::BajaEjecutada => 'Baja ejecutada',
            self::ExpedienteCerrado => 'Expediente cerrado',
            self::Cancelado => 'Cancelado',
        };
    }

    public function esFinal(): bool
    {
        return $this === self::ExpedienteCerrado || $this === self::Cancelado;
    }
}
