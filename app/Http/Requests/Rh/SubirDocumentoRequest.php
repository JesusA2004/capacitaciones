<?php

namespace App\Http\Requests\Rh;

use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class SubirDocumentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User $colaborador */
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
        ];
    }
}
