<?php

namespace App\Http\Requests\AltaPublica;

use App\Models\AltaDigital;
use Illuminate\Foundation\Http\FormRequest;

class SubirDocumentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $alta = $this->route('alta');

        return $alta instanceof AltaDigital && $alta->tokenVigente() && $alta->estado->permiteCaptura();
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
                'max:'.(config('altas.max_upload_mb') * 1024),
                'mimes:'.implode(',', config('altas.extensiones_documentos')),
            ],
        ];
    }
}
