<?php

namespace App\Http\Requests\Rh;

use Illuminate\Foundation\Http\FormRequest;

class RegistrarAvisosRequest extends FormRequest
{
    public function authorize(): bool
    {
        $colaborador = $this->route('colaborador');

        if ($this->user()?->is($colaborador)) {
            return $this->user()->can('expedientes.ver');
        }

        return $this->user()?->can('expedientes.editar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'aviso_privacidad_aceptado' => ['required', 'boolean'],
            'consentimiento_datos_aceptado' => ['required', 'boolean'],
        ];
    }
}
