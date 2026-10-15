<?php

namespace App\Http\Requests\Rh;

use App\Enums\CanalReclutamiento;
use App\Enums\CriterioFechaReporteCampanas;
use App\Enums\EstadoCampanaReclutamiento;
use App\Services\Reclutamiento\FiltrosReporteCampanas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Filtros del reporte de campañas de reclutamiento (pantalla, PDF y Excel).
 * Viajan en la query string para que el enlace se pueda compartir y la
 * descarga salga con exactamente lo que se ve en pantalla.
 */
class ReporteCampanasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reclutamiento.campanas.ver') ?? false;
    }

    /**
     * Si un filtro no es válido se vuelve al reporte sin filtros con el
     * mensaje — nunca a la misma URL inválida (redirigiría en bucle).
     */
    protected function getRedirectUrl(): string
    {
        return route('rh.campanas.reporte');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'criterio_fecha' => ['nullable', new Enum(CriterioFechaReporteCampanas::class)],
            'campanas' => ['nullable', 'array', 'max:200'],
            'campanas.*' => ['integer', 'exists:campanas_reclutamiento,id'],
            'canales' => ['nullable', 'array'],
            'canales.*' => [new Enum(CanalReclutamiento::class)],
            'estados' => ['nullable', 'array'],
            'estados.*' => [new Enum(EstadoCampanaReclutamiento::class)],
            'fases' => ['nullable', 'array'],
            'fases.*' => [Rule::in(array_keys(FiltrosReporteCampanas::fasesCandidato()))],
            'empresa_id' => ['nullable', 'integer', 'exists:empresas,id'],
            'sucursal_id' => ['nullable', 'integer', 'exists:sucursales,id'],
            'departamento_id' => ['nullable', 'integer', 'exists:departamentos,id'],
            'puesto_id' => ['nullable', 'integer', 'exists:puestos,id'],
            'vacante_id' => ['nullable', 'integer', 'exists:vacantes,id'],
            'responsable_id' => ['nullable', 'integer', 'exists:users,id'],
            // Enlace anterior de la pantalla de Campañas (mes/año).
            'mes' => ['nullable', 'integer', 'min:1', 'max:12'],
            'anio' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'desde.date_format' => 'La fecha de inicio no es válida.',
            'hasta.date_format' => 'La fecha de fin no es válida.',
            'hasta.after_or_equal' => 'La fecha de fin no puede ser anterior a la fecha de inicio.',
            'criterio_fecha' => 'Elige qué fecha se filtra: vigencia de la campaña o registro del candidato.',
            'campanas.max' => 'Elige como máximo 200 campañas (o deja «Todas» para el reporte general).',
            'campanas.*' => 'Una de las campañas elegidas ya no existe.',
            'canales.*' => 'Uno de los canales elegidos no es válido.',
            'estados.*' => 'Uno de los estados de campaña elegidos no es válido.',
            'fases.*' => 'Una de las fases de candidato elegidas no es válida.',
            'empresa_id.exists' => 'La empresa elegida no existe.',
            'sucursal_id.exists' => 'La sucursal elegida no existe.',
            'departamento_id.exists' => 'El departamento elegido no existe.',
            'puesto_id.exists' => 'El puesto elegido no existe.',
            'vacante_id.exists' => 'La vacante elegida no existe.',
            'responsable_id.exists' => 'El responsable elegido no existe.',
            'mes' => 'El mes no es válido.',
            'anio' => 'El año no es válido.',
        ];
    }

    public function filtros(): FiltrosReporteCampanas
    {
        return FiltrosReporteCampanas::desdeArreglo($this->validated());
    }
}
