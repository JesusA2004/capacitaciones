<?php

namespace App\Http\Requests\CicloLaboral;

use App\Models\EvaluacionPeriodoPrueba;
use App\Services\Contratos\VencimientoContratosService;
use App\Services\Evaluaciones\FormularioEvaluacionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Captura oficial FO-RH-EPP-001: los criterios (escala 1-4) y las preguntas
 * filtro (Sí/No) de la VERSIÓN del formulario de esta evaluación
 * (FormularioEvaluacionService; sin versión configurada, el catálogo de
 * config contratos.evaluacion_periodo_prueba_v2). `recomienda_renovar`/
 * `resultado` NUNCA se piden al cliente — EvaluacionPeriodoPruebaService los
 * calcula con la regla canónica; la obligatoriedad del comentario se valida
 * ahí también, donde vive la regla de negocio.
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
        $evaluacion = $this->route('evaluacion');
        $definicion = app(FormularioEvaluacionService::class)->definicionPeriodoPrueba($evaluacion instanceof EvaluacionPeriodoPrueba ? $evaluacion : null);

        $clavesCriterios = array_column((array) $definicion['criterios'], 'clave');
        $clavesFiltros = array_column((array) $definicion['filtros'], 'clave');

        return [
            'criterios' => ['required', 'array', 'size:'.count($clavesCriterios)],
            'criterios.*.clave' => ['required', 'string', Rule::in($clavesCriterios)],
            'criterios.*.calificacion' => ['required', 'integer', 'min:'.(int) $definicion['escala_min'], 'max:'.(int) $definicion['escala_max']],
            // Meta / Logró del renglón "Cumple sus metas" (solo criterios con
            // captura_meta): opcionales, el Service calcula el porcentaje.
            'criterios.*.meta' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'criterios.*.logro' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            // Sin preguntas filtro configuradas, el arreglo llega vacío.
            'filtros' => $clavesFiltros === [] ? ['present', 'array', 'size:0'] : ['required', 'array', 'size:'.count($clavesFiltros)],
            'filtros.*.clave' => ['required', 'string', Rule::in($clavesFiltros)],
            'filtros.*.respuesta' => ['required', 'boolean'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            // Dato de RH para "Asistencia y puntualidad" (opcional; en blanco si no se tiene).
            'faltas_injustificadas' => ['nullable', 'integer', 'min:0', 'max:366'],
            'retardos' => ['nullable', 'integer', 'min:0', 'max:366'],
            // "Hoy" en México, no en UTC (zona de la app).
            'fecha_evaluacion' => ['nullable', 'date', 'before_or_equal:'.now(VencimientoContratosService::ZONA)->toDateString()],
        ];
    }
}
