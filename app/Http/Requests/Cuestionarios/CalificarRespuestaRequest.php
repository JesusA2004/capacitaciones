<?php

namespace App\Http\Requests\Cuestionarios;

use Illuminate\Foundation\Http\FormRequest;

class CalificarRespuestaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('respuestas.calificar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'es_correcta' => ['required', 'boolean'],
            'puntos_obtenidos' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
