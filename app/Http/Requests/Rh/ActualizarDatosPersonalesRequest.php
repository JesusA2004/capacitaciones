<?php

namespace App\Http\Requests\Rh;

use Illuminate\Foundation\Http\FormRequest;

class ActualizarDatosPersonalesRequest extends FormRequest
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
            'fecha_nacimiento' => ['nullable', 'date', 'before:today'],
            'curp' => ['nullable', 'string', 'max:18'],
            'rfc' => ['nullable', 'string', 'max:13'],
            'nss' => ['nullable', 'string', 'max:11'],
            'domicilio' => ['nullable', 'string', 'max:255'],
            'correo_personal' => ['nullable', 'email', 'max:255'],
            'contacto_emergencia_nombre' => ['nullable', 'string', 'max:150'],
            'contacto_emergencia_telefono' => ['nullable', 'string', 'max:30'],
        ];
    }
}
