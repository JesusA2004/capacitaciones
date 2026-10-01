<?php

namespace App\Http\Requests\CicloLaboral;

use App\Enums\TipoBaja;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Solicitud de baja (jefe/gerente o RH): causa, motivo, fecha efectiva y
 * evidencia opcional. La autorización real (alcance, cadena de mando,
 * causas permitidas por rol) vive en CierreLaboralService::solicitar().
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
            'motivo' => ['required', 'string', 'max:2000'],
            'fecha_efectiva' => ['required', 'date'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'evidencias' => ['nullable', 'array', 'max:5'],
            'evidencias.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:'.((int) config('contratos.max_upload_mb', 20) * 1024)],
        ];
    }
}
