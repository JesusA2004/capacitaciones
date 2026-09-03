<?php

namespace App\Http\Requests\Solicitudes;

use Illuminate\Foundation\Http\FormRequest;

class SubirDocumentoSolicitudRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->route('solicitud')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'archivo' => [
                'required',
                'file',
                'max:'.(config('expedientes.max_upload_mb') * 1024),
                'mimes:'.implode(',', config('expedientes.extensiones_permitidas')),
            ],
        ];
    }
}
