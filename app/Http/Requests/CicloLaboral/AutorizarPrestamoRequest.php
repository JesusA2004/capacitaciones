<?php

namespace App\Http\Requests\CicloLaboral;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AutorizarPrestamoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('prestamos.autorizar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'monto_autorizado' => ['required', 'numeric', 'min:1', 'max:9999999'],
            'plazo_autorizado' => ['required', 'integer', 'min:1', 'max:520'],
            'periodicidad' => ['nullable', Rule::in(['semanal', 'quincenal', 'mensual'])],
            'pago_programado' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            // Contrato de crédito: datos que RH captura A MANO (nunca se
            // calculan). Opcionales aquí: la app ya instalada no los manda.
            'cargo_sin_iva' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'iva' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'monto_total_con_iva' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'numero_cheque' => ['nullable', 'string', 'max:40'],
            'fecha_primer_descuento' => ['nullable', 'date'],
            'fecha_ultimo_pago' => ['nullable', 'date', 'after_or_equal:fecha_primer_descuento'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'monto_autorizado' => 'monto autorizado',
            'plazo_autorizado' => 'plazo',
            'pago_programado' => 'pago por periodo',
            'cargo_sin_iva' => 'cargo (sin I.V.A.)',
            'iva' => 'I.V.A.',
            'monto_total_con_iva' => 'monto total (con I.V.A.)',
            'numero_cheque' => 'número de cheque',
            'fecha_primer_descuento' => 'primer pago parcial',
            'fecha_ultimo_pago' => 'último pago parcial',
        ];
    }
}
