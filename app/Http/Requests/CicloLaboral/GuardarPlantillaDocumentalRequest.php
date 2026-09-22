<?php

namespace App\Http\Requests\CicloLaboral;

use App\Enums\CategoriaDocumento;
use App\Enums\MotorPlantilla;
use App\Enums\TipoPlantillaDocumento;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Nueva versión de una plantilla documental (el texto lo aporta
 * RH/Jurídico; el sistema solo sustituye variables).
 */
class GuardarPlantillaDocumentalRequest extends FormRequest
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
            'clave' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'],
            'nombre' => ['required', 'string', 'max:160'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'tipo' => ['nullable', Rule::enum(TipoPlantillaDocumento::class)],
            'categoria' => ['nullable', Rule::enum(CategoriaDocumento::class)],
            'motor' => ['required', Rule::enum(MotorPlantilla::class)],
            'contenido_html' => ['nullable', 'string', 'max:500000'],
            'archivo' => ['nullable', 'file', 'max:'.((int) config('plantillas.max_upload_mb', 10) * 1024), 'mimes:docx'],
            'official_format_id' => ['nullable', 'integer', 'exists:official_formats,id'],
            'document_type_id' => ['nullable', 'integer', 'exists:document_types,id'],
            'empresa_id' => ['nullable', 'integer', 'exists:empresas,id'],
            'activo' => ['sometimes', 'boolean'],
            'requiere_firma_digital' => ['sometimes', 'boolean'],
            'requiere_impresion' => ['sometimes', 'boolean'],
            'requiere_firma_fisica' => ['sometimes', 'boolean'],
            'requiere_huella' => ['sometimes', 'boolean'],
            'requiere_testigos' => ['sometimes', 'boolean'],
        ];
    }
}
