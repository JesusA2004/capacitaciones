<?php

namespace App\Http\Requests\Rh;

use App\Services\Finiquitos\FiniquitoService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ajuste autorizado a un concepto automático del finiquito (001–004,
 * 101–102…): importe final + motivo. El total lo recalcula el servidor.
 */
class AjustarConceptoFiniquitoRequest extends FormRequest
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
            'concepto_clave' => ['required', 'string', Rule::in(array_keys(app(FiniquitoService::class)->conceptosAutomaticos()))],
            'importe' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'motivo' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'concepto_clave.in' => 'Ese concepto no se puede ajustar.',
            'importe.required' => 'Indica el importe autorizado.',
            'motivo.required' => 'Indica el motivo del ajuste.',
        ];
    }
}
