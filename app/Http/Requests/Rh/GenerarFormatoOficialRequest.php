<?php

namespace App\Http\Requests\Rh;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Datos para previsualizar/generar un OfficialFormat para un colaborador o
 * candidato (docs/FORMATOS_OFICIALES.md). `extra` permite a RH corregir un
 * dato faltante SOLO para ese documento, sin guardarlo en el expediente.
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
            'extra' => ['nullable', 'array'],
            'extra.*' => ['nullable', 'string', 'max:255'],
        ];
    }
}
