<?php

namespace App\Http\Requests\CartasCompromiso;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Motivo con el que RH rechaza la carta compromiso que pidió la persona (lo
 * ve ella). Quién puede: CartaCompromisoPolicy::aprobar.
 */
class RechazarCartaCompromisoRequest extends FormRequest
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
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.required' => 'Escribe el motivo del rechazo: la persona lo verá.',
            'motivo.min' => 'El motivo debe tener al menos 5 caracteres.',
            'motivo.max' => 'El motivo puede tener como máximo 500 caracteres.',
        ];
    }

    public function motivo(): string
    {
        return (string) $this->validated('motivo');
    }
}
