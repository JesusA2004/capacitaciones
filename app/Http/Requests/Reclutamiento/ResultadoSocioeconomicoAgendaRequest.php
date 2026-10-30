<?php

namespace App\Http\Requests\Reclutamiento;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/** Resultado del estudio socioeconómico: formato y evidencias van al mini expediente del candidato. */
class ResultadoSocioeconomicoAgendaRequest extends FormRequest
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
            'resultado' => ['required', 'in:favorable,no_favorable'],
            'comentario' => ['nullable', 'string', 'max:2000'],
            'direccion' => ['nullable', 'string', 'max:300'],
            'fecha_visita' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            ...ReglasArchivosCandidato::para('evidencias', ['video', 'foto', 'documento'], true),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['evidencias.required' => 'Adjunta el formato o las fotografías del estudio.'];
    }

    /**
     * @return list<UploadedFile>
     */
    public function archivos(): array
    {
        return array_values(array_filter((array) $this->file('evidencias', []), fn ($a): bool => $a instanceof UploadedFile));
    }
}
