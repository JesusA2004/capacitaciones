<?php

namespace App\Http\Requests\CicloLaboral;

use App\Enums\TipoBaja;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Inicio de baja (jefe directo o RH): causa, motivo y fecha efectiva. Sin
 * archivos: la baja se aplica de inmediato y el sistema genera la renuncia
 * o el aviso. La autorización real (alcance, cadena de mando, causas
 * permitidas por rol) vive en CierreLaboralService::solicitar().
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
        ];
    }
}
