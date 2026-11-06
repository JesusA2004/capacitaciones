<?php

namespace App\Http\Requests\Rh;

use App\Models\Colaborador;
use App\Models\EmployeeDocument;
use Illuminate\Foundation\Http\FormRequest;

class SubirDocumentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Colaborador $colaborador */
        $colaborador = $this->route('colaborador');

        return $this->user()?->can('subir', [EmployeeDocument::class, $colaborador]) ?? false;
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
            // El archivo trae también el documento par (p. ej. las dos cartas
            // de recomendación juntas): ver DocumentoParService.
            'incluye_par' => ['sometimes', 'boolean'],
            // Reverso opcional de la identificación oficial (INE): PDF o foto.
            // Si llega, el servidor lo une con `archivo` en un solo PDF
            // (DocumentoDosCarasService). Sin él, todo funciona como siempre.
            'archivo_reverso' => [
                'nullable',
                'file',
                'max:'.(config('expedientes.max_upload_mb') * 1024),
                'mimes:'.implode(',', config('expedientes.extensiones_permitidas')),
            ],
        ];
    }
}
