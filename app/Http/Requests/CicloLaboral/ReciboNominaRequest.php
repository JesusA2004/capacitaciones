<?php

namespace App\Http\Requests\CicloLaboral;

use App\Enums\TipoConceptoNomina;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Captura individual de un recibo INTERNO semanal (no fiscal).
 */
class ReciboNominaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'periodo_inicio' => ['required', 'date'],
            'periodo_fin' => ['required', 'date', 'after_or_equal:periodo_inicio'],
            'fecha_pago' => ['nullable', 'date'],
            'tipo_periodo' => ['nullable', Rule::in(['semanal', 'quincenal', 'mensual', 'libre'])],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'conceptos' => ['required', 'array', 'min:1', 'max:60'],
            'conceptos.*.tipo' => ['required', Rule::enum(TipoConceptoNomina::class)],
            'conceptos.*.concepto' => ['required', 'string', 'max:150'],
            'conceptos.*.cantidad' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'conceptos.*.importe' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'conceptos.*.observaciones' => ['nullable', 'string', 'max:255'],
            'conceptos.*.clasificacion' => ['nullable', Rule::in(['prestamo'])],
            'conceptos.*.prestamo_id' => ['nullable', 'integer', 'exists:prestamos,id'],
        ];
    }
}
