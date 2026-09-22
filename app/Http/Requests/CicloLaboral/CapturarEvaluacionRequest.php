<?php

namespace App\Http\Requests\CicloLaboral;

use App\Enums\ResultadoEvaluacion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CapturarEvaluacionRequest extends FormRequest
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
            'criterios' => ['required', 'array', 'min:1', 'max:20'],
            'criterios.*.criterio' => ['required', 'string', 'max:160'],
            'criterios.*.calificacion' => ['required', 'numeric', 'min:0', 'max:10'],
            'criterios.*.comentario' => ['nullable', 'string', 'max:500'],
            'resultado' => ['nullable', Rule::enum(ResultadoEvaluacion::class)],
            'recomienda_renovar' => ['required', 'boolean'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'fecha_evaluacion' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }
}
