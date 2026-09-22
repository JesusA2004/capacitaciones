<?php

namespace App\Http\Requests\CicloLaboral;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Decisiones simples: visto bueno (aprobado sí/no + comentario), rechazo,
 * devolución, negativa a firmar o nota de seguimiento.
 */
class DecisionRequest extends FormRequest
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
            'aprobado' => ['sometimes', 'boolean'],
            'comentario' => ['nullable', 'string', 'max:1000'],
            'motivo' => ['sometimes', 'required', 'string', 'max:1000'],
            'nota' => ['sometimes', 'required', 'string', 'max:2000'],
        ];
    }
}
