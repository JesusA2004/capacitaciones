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
            // Sin sueldo: se toma el del cálculo anterior o el salario diario
            // registrado del colaborador × 30 (CierreLaboralService::calcularFiniquito()).
            'sueldo_mensual' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999'],
            'sueldo_pendiente' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999'],
            'tipo' => ['sometimes', 'required', Rule::enum(TipoConceptoNomina::class)],
            'concepto' => ['sometimes', 'required', 'string', 'max:150'],
            'cantidad' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'importe' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999'],
            'observaciones' => ['nullable', 'string', 'max:500'],
            'referencia_pago' => ['sometimes', 'required', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sueldo_mensual.decimal' => 'El sueldo admite como máximo dos decimales (centavos).',
            'sueldo_pendiente.decimal' => 'El sueldo pendiente admite como máximo dos decimales (centavos).',
            'importe.decimal' => 'El importe admite como máximo dos decimales (centavos).',
            'importe.required' => 'Indica el importe.',
            'concepto.required' => 'Indica el nombre del concepto.',
        ];
    }

    /**
     * Sueldo mensual capturado, o null para usar el sugerido.
     */
    public function sueldoMensual(): ?float
    {
        $valor = $this->validated('sueldo_mensual');

        return is_numeric($valor) && (float) $valor > 0 ? (float) $valor : null;
    }

    public function sueldoPendiente(): float
    {
        $valor = $this->validated('sueldo_pendiente');

        return is_numeric($valor) ? (float) $valor : 0.0;
    }
}
