<?php

namespace App\Http\Requests\Bajas;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Motivo obligatorio (rechazar, reabrir, «no procede»); para cambiar la
 * causal además el id de la causal nueva. Comentario opcional de Jurídico.
 */
class MotivoBajaRequest extends FormRequest
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
        $esJuridico = $this->routeIs('*.aprobar-juridico');

        return [
            'motivo' => [$esJuridico ? 'nullable' : 'required', 'string', $esJuridico ? 'max:1000' : 'min:5', 'max:1000'],
            'causal_rotacion_id' => [$this->routeIs('*.cambiar-causal') ? 'required' : 'nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.required' => 'Escribe el motivo.',
            'motivo.min' => 'Escribe el motivo (al menos 5 caracteres).',
            'causal_rotacion_id.required' => 'Elige la causal correcta.',
        ];
    }

    public function motivo(): string
    {
        return trim((string) $this->validated('motivo', ''));
    }
}
