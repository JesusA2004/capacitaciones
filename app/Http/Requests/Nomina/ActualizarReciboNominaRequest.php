<?php

namespace App\Http\Requests\Nomina;

use App\Enums\TipoConceptoNomina;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ajuste individual de un recibo de nómina (borrador o emitido): la lista
 * completa de conceptos reemplaza a la anterior.
 */
class ActualizarReciboNominaRequest extends FormRequest
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
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'conceptos' => ['required', 'array', 'min:1', 'max:60'],
            'conceptos.*.tipo' => ['required', Rule::enum(TipoConceptoNomina::class)],
            'conceptos.*.clave' => ['nullable', 'string', 'max:10'],
            'conceptos.*.concepto' => ['required', 'string', 'max:150'],
            'conceptos.*.cantidad' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'conceptos.*.importe' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'conceptos.*.observaciones' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'conceptos.*.concepto' => 'concepto',
            'conceptos.*.importe' => 'importe',
        ];
    }
}
