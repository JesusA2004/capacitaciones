<?php

namespace App\Http\Requests\Administracion;

use Illuminate\Foundation\Http\FormRequest;

class GuardarResponsableAprobacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can('organigrama.ver') && $this->user()->can('responsables_aprobacion.administrar');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'colaborador_id' => ['nullable', 'integer', 'exists:colaboradores,id', 'required_without:puesto_id', 'prohibits:puesto_id'],
            'puesto_id' => ['nullable', 'integer', 'exists:puestos,id', 'required_without:colaborador_id'],
            'responsable_colaborador_id' => ['required', 'integer', 'exists:colaboradores,id'],
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'colaborador_id.required_without' => 'Elige a una persona o un puesto.',
            'puesto_id.required_without' => 'Elige a una persona o un puesto.',
            'colaborador_id.prohibits' => 'Elige a una persona o un puesto, no ambos.',
            'responsable_colaborador_id.required' => 'Elige quién aprobará.',
            'motivo.required' => 'Escribe el motivo (queda en la bitácora).',
            'motivo.min' => 'Escribe un motivo un poco más claro (mínimo 5 letras).',
        ];
    }
}
