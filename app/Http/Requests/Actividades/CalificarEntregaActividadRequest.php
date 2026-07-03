<?php

namespace App\Http\Requests\Actividades;

use Illuminate\Foundation\Http\FormRequest;

class CalificarEntregaActividadRequest extends FormRequest
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
            'aprobada' => ['required', 'boolean'],
            'calificacion' => ['nullable', 'integer', 'min:0', 'max:100'],
            'retroalimentacion' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
