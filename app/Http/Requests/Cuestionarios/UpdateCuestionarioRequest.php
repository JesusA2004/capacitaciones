<?php

namespace App\Http\Requests\Cuestionarios;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCuestionarioRequest extends FormRequest
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
            'titulo' => ['required', 'string', 'max:180'],
            'instrucciones' => ['nullable', 'string', 'max:2000'],
            'calificacion_minima' => ['required', 'integer', 'min:1', 'max:100'],
            'intentos_maximos' => ['nullable', 'integer', 'min:1'],
            'tiempo_limite_minutos' => ['nullable', 'integer', 'min:1'],
            'aleatorizar_preguntas' => ['boolean'],
            'mostrar_retroalimentacion' => ['boolean'],
        ];
    }
}
