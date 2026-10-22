<?php

namespace App\Http\Requests\FormulariosEvaluacion;

use App\Enums\ColorAcentoFormulario;
use App\Enums\EstiloEncabezadoFormulario;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Autoguardado del borrador de un formulario de evaluación. La estructura
 * de `definicion` (secciones, preguntas, reglas) la limpia y valida
 * FormularioEvaluacionService — aquí solo la forma general. La autorización
 * vive en FormularioEvaluacionPolicy + el Service.
 */
class GuardarFormularioEvaluacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'titulo' => ['present', 'nullable', 'string', 'max:200'],
            'descripcion' => ['nullable', 'string', 'max:3000'],
            'codigo' => ['nullable', 'string', 'max:40'],
            'diseno' => ['nullable', 'array'],
            'diseno.acento' => ['nullable', Rule::enum(ColorAcentoFormulario::class)],
            'diseno.estilo_encabezado' => ['nullable', Rule::enum(EstiloEncabezadoFormulario::class)],
            'definicion' => ['required', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'titulo.max' => 'El título puede tener hasta 200 caracteres.',
            'descripcion.max' => 'La descripción puede tener hasta 3000 caracteres.',
            'definicion.required' => 'El formulario no tiene preguntas que guardar.',
        ];
    }
}
