<?php

namespace App\Http\Requests\CicloLaboral;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Generar un documento laboral para un colaborador a partir de una
 * plantilla (por clave o por id). `extra` solo acepta valores escalares
 * cortos: son variables de sustitución, nunca HTML ni archivos.
 */
class GenerarDocumentoLaboralRequest extends FormRequest
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
        return [
            'clave' => ['required_without:plantilla_id', 'nullable', 'string', 'max:60'],
            'plantilla_id' => ['required_without:clave', 'nullable', 'integer', 'exists:document_templates,id'],
            'contrato_id' => ['nullable', 'integer', 'exists:contratos_laborales,id'],
            'titulo' => ['nullable', 'string', 'max:160'],
            'extra' => ['nullable', 'array', 'max:50'],
            'extra.*' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
