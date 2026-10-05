<?php

namespace App\Http\Requests\Colaboradores;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rechazo de un cambio de foto de perfil (web y app). La autorización la
 * hace CambioFotoPerfilPolicy en el controlador.
 */
class RechazarCambioFotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'motivo' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.max' => 'El motivo no debe pasar de 500 caracteres.',
        ];
    }
}
