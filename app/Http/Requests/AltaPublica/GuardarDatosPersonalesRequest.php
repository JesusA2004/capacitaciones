<?php

namespace App\Http\Requests\AltaPublica;

use App\Models\AltaDigital;
use App\Services\Colaboradores\DomicilioService;
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
            // Opcional: el acceso es con usuario (primer nombre + primer apellido).
            'correo' => ['nullable', 'email', 'max:255'],
            'fecha_nacimiento' => ['required', 'date', 'before:-18 years'],
            'curp' => ['required', 'string', 'max:18'],
            'rfc' => ['nullable', 'string', 'max:13'],
            'nss' => ['nullable', 'string', 'max:11'],
            // Domicilio estructurado (todo menos el número interior es
            // obligatorio): el texto `domicilio` se compone al guardar.
            ...app(DomicilioService::class)->reglas(requerido: true),
            'contacto_emergencia_nombre' => ['required', 'string', 'max:150'],
            'contacto_emergencia_telefono' => ['required', 'string', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return app(DomicilioService::class)->mensajes();
    }
}
