<?php

namespace App\Http\Requests\Rh;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGeneratedDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('plantillas.generar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'document_template_id' => ['required', 'integer', 'exists:document_templates,id'],
            'solicitud_id' => ['nullable', 'integer', 'exists:solicitudes_internas,id', 'prohibits:solicitud_vacaciones_id'],
            'solicitud_vacaciones_id' => ['nullable', 'integer', 'exists:solicitudes_vacaciones,id'],
            'tipo_sujeto' => ['required_without_all:solicitud_id,solicitud_vacaciones_id', 'nullable', Rule::in(['colaborador', 'candidato'])],
            'sujeto_id' => ['required_without_all:solicitud_id,solicitud_vacaciones_id', 'nullable', 'integer'],
            'extra' => ['nullable', 'array'],
            'extra.*' => ['nullable', 'string', 'max:255'],
        ];
    }
}
