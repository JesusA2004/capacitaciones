<?php

namespace App\Http\Requests\Onboarding;

use App\Enums\TipoModuloOnboarding;
use App\Models\OnboardingModulo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarModuloOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('onboarding.gestionar') ?? false) && $this->user()->can('onboarding.configurar');
    }

    /**
     * Un módulo con evaluación formal (Inducción Mr. Lana) no usa el quiz
     * genérico ni el texto de contenido: solo se editan sus datos generales
     * (OnboardingCatalogoService::guardarModulo()).
     */
    private function esFormal(): bool
    {
        $modulo = $this->route('modulo');

        return $modulo instanceof OnboardingModulo && $modulo->evaluacion_formal_clave !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $formal = $this->esFormal();

        return [
            'titulo' => ['required', 'string', 'max:190'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'tipo' => ['required', Rule::enum(TipoModuloOnboarding::class)],
            'puesto_id' => ['nullable', 'integer', 'exists:puestos,id'],
            'orden' => ['required', 'integer', 'min:1', 'max:999'],
            'contenido_url' => ['nullable', 'url', 'max:500'],
            'contenido' => $formal ? ['nullable'] : ['nullable', 'string', 'max:20000'],
            'preguntas' => $formal ? ['nullable', 'array'] : ['required', 'array', 'min:1', 'max:50'],
            'preguntas.*.pregunta' => $formal ? ['nullable'] : ['required', 'string', 'max:500'],
            'preguntas.*.opciones' => $formal ? ['nullable'] : ['required', 'array', 'min:2', 'max:8'],
            'preguntas.*.opciones.*' => ['nullable', 'string', 'max:300'],
            'preguntas.*.correcta' => $formal ? ['nullable'] : ['required', 'integer', 'min:0'],
            'calificacion_minima' => $formal ? ['nullable', 'numeric'] : ['required', 'numeric', 'min:0', 'max:10'],
            'obligatorio' => ['boolean'],
            'activo' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'preguntas.required' => 'Agrega al menos una pregunta para evaluar este módulo.',
            'preguntas.min' => 'Agrega al menos una pregunta para evaluar este módulo.',
            'contenido_url.url' => 'Escribe un enlace completo, que empiece con https://',
            'calificacion_minima.max' => 'La calificación mínima va de 0 a 10.',
        ];
    }
}
