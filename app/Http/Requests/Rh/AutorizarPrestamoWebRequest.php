<?php

namespace App\Http\Requests\Rh;

use App\Http\Requests\CicloLaboral\AutorizarPrestamoRequest;

/**
 * «Autorizar préstamo» desde la web (detalle de la solicitud): mismas reglas
 * que la API, pero aquí RH SIEMPRE captura los datos del contrato de crédito
 * que el formato pide (cargo, I.V.A., monto total, primer y último pago).
 * Se capturan a mano: no se calculan ni se cuadran entre sí. El No. de
 * cheque es opcional (entrega por transferencia).
 */
class AutorizarPrestamoWebRequest extends AutorizarPrestamoRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'cargo_sin_iva' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'iva' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'monto_total_con_iva' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'fecha_primer_descuento' => ['required', 'date'],
            'fecha_ultimo_pago' => ['required', 'date', 'after_or_equal:fecha_primer_descuento'],
        ];
    }
}
