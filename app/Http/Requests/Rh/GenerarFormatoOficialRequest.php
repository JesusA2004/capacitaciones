<?php

namespace App\Http\Requests\Rh;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Preparar / previsualizar / generar un documento desde una plantilla
 * oficial (docs/FORMATOS_OFICIALES.md). Solo se capturan los CAMPOS
 * MANUALES que la plantilla declara (`manuales`); los datos que People ya
 * conoce nunca se piden ni se sobrescriben aquí — si faltan, se completan
 * en el expediente.
 */
class GenerarFormatoOficialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('formatos_oficiales.generar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tipo_sujeto' => ['required', Rule::in(['colaborador', 'candidato'])],
            'sujeto_id' => ['required', 'integer'],
            'solicitud_id' => ['nullable', 'integer'],
            'prestamo_id' => ['nullable', 'integer'],
            'contrato_id' => ['nullable', 'integer'],
            'manuales' => ['nullable', 'array', 'max:100'],
            'manuales.*' => ['nullable', 'string', 'max:2000'],
            'guardar_en_expediente' => ['nullable', 'boolean'],
        ];
    }
}
