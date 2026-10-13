<?php

namespace App\Http\Requests\Reclutamiento;

use App\Services\Reclutamiento\ExpedienteCandidatoService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Archivos agregados al mini expediente DESPUÉS de registrar la etapa. La
 * autorización real (permiso + alcance) la valida ExpedienteCandidatoService.
 */
class AgregarEvidenciasCandidatoRequest extends FormRequest
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
        $grupos = $this->input('etapa') === ExpedienteCandidatoService::ETAPA_SOCIOECONOMICO
            ? ['video', 'foto', 'documento']
            : ['documento', 'foto'];

        return [
            'etapa' => ['required', Rule::in(ExpedienteCandidatoService::ETAPAS)],
            'registro_id' => ['nullable', 'integer'],
            ...ReglasArchivosCandidato::para('archivos', $grupos, requerido: true),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['archivos.required' => 'Elige al menos un archivo.'];
    }
}
