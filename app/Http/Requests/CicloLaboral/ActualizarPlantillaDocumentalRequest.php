<?php

namespace App\Http\Requests\CicloLaboral;

use App\Enums\CategoriaDocumento;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActualizarPlantillaDocumentalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('plantillas_documentales.administrar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['sometimes', 'string', 'max:160'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'categoria' => ['nullable', Rule::enum(CategoriaDocumento::class)],
            'document_type_id' => ['nullable', 'integer', 'exists:document_types,id'],
            'activo' => ['sometimes', 'boolean'],
            'requiere_firma_digital' => ['sometimes', 'boolean'],
            'requiere_impresion' => ['sometimes', 'boolean'],
            'requiere_firma_fisica' => ['sometimes', 'boolean'],
            'requiere_huella' => ['sometimes', 'boolean'],
            'requiere_testigos' => ['sometimes', 'boolean'],
        ];
    }
}
