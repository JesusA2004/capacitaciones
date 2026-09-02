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
            'tipo_sujeto' => ['required', Rule::in(['colaborador', 'candidato'])],
            'sujeto_id' => ['required', 'integer'],
            'extra' => ['nullable', 'array'],
            'extra.*' => ['nullable', 'string', 'max:255'],
        ];
    }
}
