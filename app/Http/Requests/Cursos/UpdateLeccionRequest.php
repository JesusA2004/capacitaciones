<?php

namespace App\Http\Requests\Cursos;

use App\Enums\TipoLeccion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateLeccionRequest extends FormRequest
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
            'tipo' => ['required', new Enum(TipoLeccion::class)],
            'contenido' => ['nullable', 'string'],
            'url' => [
                Rule::requiredIf(fn () => $this->input('tipo') === TipoLeccion::Enlace->value),
                'nullable', 'url', 'max:2048',
            ],
            'obligatoria' => ['boolean'],
            'duracion_estimada_minutos' => ['nullable', 'integer', 'min:1'],
            'recurso_multimedia_id' => ['nullable', 'integer', 'exists:recursos_multimedia,id'],
            'requisitos_previos' => ['array'],
            'requisitos_previos.*' => ['integer', 'exists:lecciones,id'],
        ];
    }
}
