<?php

namespace App\Http\Requests\Nomina;

use App\Enums\TipoConceptoNomina;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cambio en bloque a los recibos de una quincena: agregar/cambiar o quitar
 * un concepto a todos (o solo a los seleccionados).
 */
class CambioMasivoRecibosRequest extends FormRequest
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
            'periodo' => ['required', 'string', 'regex:/^\d{4}-\d{2}-[12]$/'],
            'accion' => ['required', Rule::in(['agregar', 'quitar'])],
            'tipo' => ['required', Rule::enum(TipoConceptoNomina::class)],
            'concepto' => ['required', 'string', 'max:150'],
            'importe' => ['required_if:accion,agregar', 'nullable', 'numeric', 'min:0', 'max:9999999'],
            'ids' => ['nullable', 'array', 'max:2000'],
            'ids.*' => ['integer'],
        ];
    }
}
