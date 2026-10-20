<?php

namespace App\Http\Requests\CicloLaboral;

use App\Enums\TipoBaja;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mismas reglas que SolicitarCierreRequest (tipo de baja de tres, causal de
 * rotación del tipo, detalle libre opcional).
 */
class IniciarCierreRequest extends FormRequest
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
            'tipo_baja' => ['required', Rule::enum(TipoBaja::class)],
            'causal_rotacion_id' => ['nullable', 'integer'],
            'motivo' => ['nullable', 'string', 'max:2000'],
            'fecha_efectiva' => ['required', 'date'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
