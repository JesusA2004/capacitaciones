<?php

namespace App\Http\Requests\Reclutamiento;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Decisión de un filtro del reclutamiento (perfil, revisión de
 * psicométricas, conclusión de referencias): viable o no, con observaciones
 * (obligatorias si no es viable — lo exige CandidatoWorkflowService).
 * La autorización real (permiso del paso + alcance) vive en el service.
 */
class EvaluarFiltroCandidatoRequest extends FormRequest
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
            'viable' => ['required', 'boolean'],
            'observaciones' => ['nullable', 'string', 'max:4000', 'required_if:viable,false,0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['observaciones.required_if' => 'Indica el motivo cuando el candidato no es viable.'];
    }
}
