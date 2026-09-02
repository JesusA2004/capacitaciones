<?php

namespace App\Http\Requests\Rh;

use Illuminate\Foundation\Http\FormRequest;

class SubirCvCandidatoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('candidato')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cv' => [
                'required',
                'file',
                'max:'.(config('reclutamiento.max_upload_mb') * 1024),
                'mimes:'.implode(',', config('reclutamiento.extensiones_permitidas')),
            ],
        ];
    }
}
