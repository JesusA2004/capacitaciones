<?php

namespace App\Http\Requests\Cuestionarios;

use App\Models\BancoPregunta;
use Illuminate\Foundation\Http\FormRequest;

class StoreBancoPreguntaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', BancoPregunta::class) ?? false;
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
