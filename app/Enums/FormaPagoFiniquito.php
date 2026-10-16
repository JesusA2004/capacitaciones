<?php

namespace App\Enums;

/**
 * Forma en que RH entregó el finiquito al excolaborador (se registra al
 * «Registrar entrega de finiquito», que cierra el cierre laboral — ver
 * App\Services\CierreLaboral\CierreLaboralService::registrarEntregaFiniquito()).
 */
enum FormaPagoFiniquito: string
{
    case Transferencia = 'transferencia';
    case Efectivo = 'efectivo';
    case Cheque = 'cheque';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Transferencia => 'Transferencia',
            self::Efectivo => 'Efectivo',
            self::Cheque => 'Cheque',
        };
    }
}
