<?php

namespace App\Http\Requests\Rh;

use Illuminate\Foundation\Http\FormRequest;

class ActualizarAjustesFiniquitoRequest extends FormRequest
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
            'bonos_extra' => ['nullable', 'numeric', 'min:0'],
            'descuentos' => ['nullable', 'numeric', 'min:0'],
            'adeudos' => ['nullable', 'numeric', 'min:0'],
            'otros_conceptos' => ['nullable', 'array'],
            'otros_conceptos.*' => ['numeric'],
            'comentarios_ajuste' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
