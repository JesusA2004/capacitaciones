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
            ...ReglasArchivosCandidato::para('archivos', ['documento', 'foto']),
        ];
    }
}
