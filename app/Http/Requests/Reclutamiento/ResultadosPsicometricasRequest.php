<?php

namespace App\Http\Requests\Reclutamiento;

use Illuminate\Foundation\Http\FormRequest;

class ResultadosPsicometricasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'resumen' => ['required', 'string', 'max:4000'],
            'archivos' => ['nullable', 'array', 'max:5'],
            // MIME real del contenido (mimes: usa finfo), tamaño acotado.
            'archivos.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:'.((int) config('contratos.max_upload_mb', 20) * 1024)],
        ];
    }
}
