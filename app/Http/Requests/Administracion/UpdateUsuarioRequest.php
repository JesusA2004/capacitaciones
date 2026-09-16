<?php

namespace App\Http\Requests\Administracion;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edita solo la CUENTA DE ACCESO (correo, zona horaria, roles) — los datos
 * de persona/empleo se editan desde Rh\ExpedienteController::actualizarDatosPersonales().
 */
class UpdateUsuarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('usuario')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('usuario'))],
            'zona_horaria' => ['nullable', 'string', 'max:60'],
            'roles' => ['array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ];
    }
}
