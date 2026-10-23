<?php

namespace App\Http\Requests\CicloLaboral;

use App\Models\EvaluacionPeriodoPrueba;
use App\Services\Contratos\VencimientoContratosService;
use App\Services\Evaluaciones\FormularioEvaluacionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Autoevaluación oficial FO-RH-EPP-001 (la contesta el colaborador evaluado): los criterios (escala 1-4) y las preguntas
 * filtro (Sí/No) de la VERSIÓN del formulario de esta evaluación
 * (FormularioEvaluacionService; sin versión configurada, el catálogo de
 * config contratos.evaluacion_periodo_prueba_v2). `recomienda_renovar`/
 * `resultado` NUNCA se piden al cliente — EvaluacionPeriodoPruebaService los
 * calcula con la regla canónica; la obligatoriedad del comentario se valida
 * ahí también, donde vive la regla de negocio.
 */
class CapturarEvaluacionRequest extends FormRequest
{
    /**
     * Autoevaluación: solo la persona evaluada la contesta (Policy
     * «contestar»). Se revisa ANTES de validar: RH o el jefe reciben 403,
     * nunca errores de validación de un formulario que no les toca.
     */
    public function authorize(): bool
    {
        $evaluacion = $this->route('evaluacion');

        return $evaluacion instanceof EvaluacionPeriodoPrueba && $this->user()?->can('contestar', $evaluacion) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $evaluacion = $this->route('evaluacion');
        $definicion = app(FormularioEvaluacionService::class)->definicionPeriodoPrueba($evaluacion instanceof EvaluacionPeriodoPrueba ? $evaluacion : null);

        $clavesCriterios = array_column((array) $definicion['criterios'], 'clave');
        $clavesFiltros = array_column((array) $definicion['filtros'], 'clave');

        return [
            'criterios' => ['required', 'array', 'size:'.count($clavesCriterios)],
            'criterios.*.clave' => ['required', 'string', Rule::in($clavesCriterios)],
            'criterios.*.calificacion' => ['required', 'integer', 'min:'.(int) $definicion['escala_min'], 'max:'.(int) $definicion['escala_max']],
            // Sin preguntas filtro configuradas, el arreglo llega vacío.
            'filtros' => $clavesFiltros === [] ? ['present', 'array', 'size:0'] : ['required', 'array', 'size:'.count($clavesFiltros)],
            'filtros.*.clave' => ['required', 'string', Rule::in($clavesFiltros)],
            'filtros.*.respuesta' => ['required', 'boolean'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            // "Hoy" en México, no en UTC (zona de la app).
            'fecha_evaluacion' => ['nullable', 'date', 'before_or_equal:'.now(VencimientoContratosService::ZONA)->toDateString()],
        ];
    }
}
