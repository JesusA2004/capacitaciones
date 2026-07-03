<?php

namespace App\Http\Requests\Cursos;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCursoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('curso')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'titulo' => ['required', 'string', 'max:180'],
            'descripcion' => ['nullable', 'string'],
            'objetivo' => ['nullable', 'string'],
            'duracion_estimada_minutos' => ['nullable', 'integer', 'min:1'],
            'disponible_desde' => ['nullable', 'date'],
            'disponible_hasta' => ['nullable', 'date', 'after_or_equal:disponible_desde'],
            'calificacion_minima' => ['nullable', 'integer', 'min:0', 'max:100'],
            'intentos_maximos' => ['nullable', 'integer', 'min:1'],
            'requiere_orden' => ['boolean'],
            'genera_constancia' => ['boolean'],
            'alcance_global' => ['boolean'],
            'etiquetas' => ['array'],
            'etiquetas.*' => ['string', 'max:40'],
            'responsable_id' => ['nullable', 'integer', 'exists:users,id'],
            'requisitos_previos' => ['array'],
            'requisitos_previos.*' => ['integer', 'exists:cursos,id'],
        ];
    }

    public function attributes(): array
    {
        return [
            'titulo' => 'título',
        ];
    }
}
