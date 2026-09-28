<?php

namespace App\Http\Requests\Rh;

use App\Enums\TipoPlantillaDocumento;
use App\Models\DocumentTemplate;
use App\Services\Plantillas\DocxUploadValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class StoreDocumentTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', DocumentTemplate::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:150'],
            'tipo' => ['required', new Enum(TipoPlantillaDocumento::class)],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'empresa_id' => ['nullable', 'integer', 'exists:empresas,id'],
            'sucursal_id' => ['nullable', 'integer', 'exists:sucursales,id'],
            'puesto_id' => ['nullable', 'integer', 'exists:puestos,id'],
            'archivo' => [
                'required',
                'file',
                'max:'.(config('plantillas.max_upload_mb') * 1024),
                'mimes:'.implode(',', config('plantillas.extensiones_permitidas')),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $archivo = $this->file('archivo');

            if ($archivo !== null && $archivo->isValid() && ! DocxUploadValidator::esZipSeguro($archivo)) {
                $validator->errors()->add('archivo', 'El archivo no es un DOCX válido o su contenido es demasiado grande al descomprimir.');
            }
        });
    }
}
