<?php

namespace App\Http\Requests\CicloLaboral;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Captura oficial FO-RH-EPP-001: los 9 criterios (escala 1-4) y las 5
 * preguntas filtro (Sí/No) del catálogo real (config
 * contratos.evaluacion_periodo_prueba_v2). `recomienda_renovar`/`resultado`
 * NUNCA se piden al cliente — EvaluacionPeriodoPruebaService los calcula
 * con la regla canónica; la obligatoriedad del comentario (1/2 o un Sí) se
 * valida ahí también, donde vive la regla de negocio.
 */
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
        $clavesCriterios = array_column((array) config('contratos.evaluacion_periodo_prueba_v2.criterios', []), 'clave');
        $clavesFiltros = array_column((array) config('contratos.evaluacion_periodo_prueba_v2.filtros', []), 'clave');

        return [
            'criterios' => ['required', 'array', 'size:'.count($clavesCriterios)],
            'criterios.*.clave' => ['required', 'string', Rule::in($clavesCriterios)],
            'criterios.*.calificacion' => ['required', 'integer', 'min:1', 'max:4'],
            'filtros' => ['required', 'array', 'size:'.count($clavesFiltros)],
            'filtros.*.clave' => ['required', 'string', Rule::in($clavesFiltros)],
            'filtros.*.respuesta' => ['required', 'boolean'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'fecha_evaluacion' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }
}
