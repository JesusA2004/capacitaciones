<?php

namespace App\Http\Requests\Onboarding;

use App\Enums\TipoModuloOnboarding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarModuloOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('onboarding.gestionar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'titulo' => ['required', 'string', 'max:190'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'tipo' => ['required', Rule::enum(TipoModuloOnboarding::class)],
            'puesto_id' => ['nullable', 'integer', 'exists:puestos,id'],
            'orden' => ['required', 'integer', 'min:1', 'max:999'],
            'contenido_url' => ['nullable', 'url', 'max:500'],
            'contenido' => ['nullable', 'string', 'max:20000'],
            'preguntas' => ['required', 'array', 'min:1', 'max:50'],
            'preguntas.*.pregunta' => ['required', 'string', 'max:500'],
            'preguntas.*.opciones' => ['required', 'array', 'min:2', 'max:8'],
            'preguntas.*.opciones.*' => ['nullable', 'string', 'max:300'],
            'preguntas.*.correcta' => ['required', 'integer', 'min:0'],
            'calificacion_minima' => ['required', 'numeric', 'min:0', 'max:10'],
            'obligatorio' => ['boolean'],
            'activo' => ['boolean'],
        ];
    }
}
