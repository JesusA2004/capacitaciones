<?php

namespace App\Http\Requests\Reclutamiento;

use App\Enums\ResultadoEtapaCandidato;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegistrarEntrevistaRequest extends FormRequest
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
            'realizada_en' => ['required', 'date', 'before_or_equal:now'],
            'resultado' => ['required', Rule::enum(ResultadoEtapaCandidato::class)],
            'motivo_rechazo_id' => ['nullable', 'integer', 'exists:motivos_rechazo_candidato,id'],
            'recontratable' => ['nullable', 'boolean'],
            'observaciones' => ['nullable', 'string', 'max:4000', 'required_if:resultado,no_viable'],
            'entrevistador_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['observaciones.required_if' => 'Indica el motivo cuando el candidato no sigue viable.'];
    }
}
