<?php

namespace App\Http\Requests\Rh;

use Illuminate\Foundation\Http\FormRequest;

class AplicarContactosEmergenciaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('expedientes.editar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'filas' => ['nullable', 'array'],
            'filas.*' => ['integer', 'min:0'],
        ];
    }
}
