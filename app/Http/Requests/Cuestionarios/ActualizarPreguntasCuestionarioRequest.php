<?php

namespace App\Http\Requests\Cuestionarios;

use Illuminate\Foundation\Http\FormRequest;

class ActualizarPreguntasCuestionarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('cuestionario')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'preguntas' => ['array'],
            'preguntas.*.pregunta_id' => ['required', 'integer', 'exists:preguntas,id'],
            'preguntas.*.puntos' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
