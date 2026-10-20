<?php

namespace App\Http\Requests\Rh;

use Illuminate\Foundation\Http\FormRequest;

class AsignarColaboradorContactosEmergenciaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('expedientes.editar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'colaborador_id' => ['required', 'integer', 'exists:colaboradores,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'colaborador_id.required' => 'Elige al colaborador de esta fila.',
            'colaborador_id.exists' => 'El colaborador elegido no existe.',
        ];
    }
}
