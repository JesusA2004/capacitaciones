<?php

namespace App\Http\Requests\Cuestionarios;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBancoPreguntaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('banco')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:150'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
