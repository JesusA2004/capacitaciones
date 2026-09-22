<?php

namespace App\Http\Requests\CicloLaboral;

use App\Enums\TipoConceptoNomina;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Operaciones del finiquito dentro del cierre laboral: cálculo, conceptos
 * (percepción/deducción) y confirmación de pago. Las reglas se aplican solo
 * a los campos presentes en cada operación.
 */
class FiniquitoCierreRequest extends FormRequest
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
            'sueldo_mensual' => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999'],
            'sueldo_pendiente' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'tipo' => ['sometimes', 'required', Rule::enum(TipoConceptoNomina::class)],
            'concepto' => ['sometimes', 'required', 'string', 'max:150'],
            'cantidad' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'importe' => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999'],
            'observaciones' => ['nullable', 'string', 'max:500'],
            'referencia_pago' => ['sometimes', 'required', 'string', 'max:120'],
        ];
    }
}
