<?php

namespace App\Http\Requests\Reaperturas;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La autoridad la decide ReaperturaService (web y API por igual).
 */
class ReabrirDecisionRequest extends FormRequest
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
        return ['motivo' => ['required', 'string', 'min:5', 'max:1000']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.required' => 'Escribe el motivo para reabrir.',
            'motivo.min' => 'Explica el motivo con al menos 5 caracteres.',
        ];
    }
}
