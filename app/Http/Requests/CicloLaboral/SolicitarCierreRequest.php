<?php

namespace App\Http\Requests\CicloLaboral;

use App\Enums\TipoBaja;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Inicio de baja (jefe directo o RH): tipo (solo renuncia, rescisión o fin
 * de contrato), motivo = causal de rotación del tipo, detalle libre opcional
 * y fecha efectiva. Sin archivos: la baja se aplica de inmediato y el
 * sistema genera los documentos del tipo. La autorización real (alcance,
 * cadena de mando, tipos permitidos por rol) y que la causal pertenezca al
 * tipo viven en CierreLaboralService::solicitar() / CausalRotacionService.
 */
class SolicitarCierreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tipo_baja' => ['required', Rule::enum(TipoBaja::class)],
            'causal_rotacion_id' => ['nullable', 'integer'],
            'motivo' => ['nullable', 'string', 'max:2000'],
            'fecha_efectiva' => ['required', 'date'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo_baja.required' => 'Elige el tipo de baja: renuncia, rescisión o fin de contrato.',
            'tipo_baja.enum' => 'Elige el tipo de baja: renuncia, rescisión o fin de contrato.',
        ];
    }
}
