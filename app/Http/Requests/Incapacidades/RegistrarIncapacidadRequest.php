<?php

namespace App\Http\Requests\Incapacidades;

use App\Enums\SecuenciaIncapacidad;
use App\Enums\SubtipoRiesgoTrabajo;
use App\Enums\TipoIncapacidad;
use App\Models\Colaborador;
use App\Models\Incapacidad;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegistrarIncapacidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('incapacidades.registrar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $archivo = ['file', 'mimes:'.implode(',', (array) config('incapacidades.documentos.mimes', [])), 'max:'.(int) config('incapacidades.documentos.max_kb', 15360)];

        return [
            'colaborador_id' => ['required', 'integer', Rule::exists(Colaborador::class, 'id')],
            'tipo' => ['required', Rule::enum(TipoIncapacidad::class)],
            // La subsecuente hereda el subtipo de su inicial.
            'subtipo_riesgo' => ['nullable', Rule::enum(SubtipoRiesgoTrabajo::class), Rule::requiredIf(fn (): bool => $this->input('tipo') === TipoIncapacidad::RiesgoTrabajo->value && $this->input('secuencia') === SecuenciaIncapacidad::Inicial->value)],
            'secuencia' => ['required', Rule::enum(SecuenciaIncapacidad::class)],
            'incapacidad_inicial_id' => ['nullable', 'integer', Rule::exists(Incapacidad::class, 'id'), 'required_if:secuencia,'.SecuenciaIncapacidad::Subsecuente->value],
            'serie_folio_imss' => ['required', 'string', 'max:40'],
            'fecha_inicio' => ['required', 'date'],
            'dias' => ['required', 'integer', 'min:1', 'max:'.(int) config('incapacidades.dias_maximos', 365)],
            'fecha_evento' => ['nullable', 'date'],
            'descripcion_evento' => ['nullable', 'string', 'max:2000'],
            'fecha_st7' => ['nullable', 'date'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'certificado' => ['required', ...$archivo],
            'st7' => ['nullable', ...$archivo],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'colaborador_id.required' => 'Elige al colaborador.',
            'subtipo_riesgo.required' => 'Elige el tipo de riesgo de trabajo.',
            'incapacidad_inicial_id.required_if' => 'Elige la incapacidad inicial a la que se liga.',
            'serie_folio_imss.required' => 'La serie y el folio del certificado del IMSS son obligatorios.',
            'dias.min' => 'El número de días debe ser mayor a cero.',
            'certificado.required' => 'Adjunta el certificado de incapacidad del IMSS.',
        ];
    }
}
