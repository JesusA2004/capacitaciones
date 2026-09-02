<?php

namespace App\Http\Requests\AltaPublica;

use App\Models\AltaDigital;
use Illuminate\Foundation\Http\FormRequest;

class GuardarDatosPersonalesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $alta = $this->route('alta');

        return $alta instanceof AltaDigital && $alta->tokenVigente() && $alta->estado->permiteCaptura();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:150'],
            'apellidos' => ['required', 'string', 'max:150'],
            'telefono' => ['required', 'string', 'max:30'],
            'correo' => ['required', 'email', 'max:255'],
            'fecha_nacimiento' => ['required', 'date', 'before:-18 years'],
            'curp' => ['required', 'string', 'max:18'],
            'rfc' => ['nullable', 'string', 'max:13'],
            'nss' => ['nullable', 'string', 'max:11'],
            'domicilio' => ['required', 'string', 'max:255'],
            'contacto_emergencia_nombre' => ['required', 'string', 'max:150'],
            'contacto_emergencia_telefono' => ['required', 'string', 'max:30'],
        ];
    }
}
