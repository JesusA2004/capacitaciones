<?php

namespace App\Http\Requests\Rh;

use Illuminate\Foundation\Http\FormRequest;

class SubirFormatoFirmadoRequest extends FormRequest
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
            'document_type_id' => ['required', 'integer', 'exists:document_types,id'],
            'archivo' => [
                'required',
                'file',
                'max:'.(config('expedientes.max_upload_mb') * 1024),
                'mimes:'.implode(',', config('expedientes.extensiones_permitidas')),
            ],
        ];
    }
}
