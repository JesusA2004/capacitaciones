<?php

namespace App\Http\Requests\Api\V1;

use App\Concerns\PasswordValidationRules;
use App\Rules\NombreSinApellidos;
use App\Services\Colaboradores\DomicilioService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Registro publico de colaborador desde un QR de incorporacion: no requiere
 * `auth:sanctum` (todavía no existe cuenta) ni permiso alguno — la única
 * puerta de entrada es el token del QR, validado explícitamente en
 * App\Http\Controllers\Api\V1\IncorporacionInvitacionController::registrar()
 * antes de crear la cuenta.
 */
class RegistrarDesdeQrRequest extends FormRequest
{
    use PasswordValidationRules;

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
            'name' => ['required', 'string', 'max:150', new NombreSinApellidos],
            'apellidos' => ['nullable', 'string', 'max:150'],
            // Opcional: se entra con el usuario (primer nombre + primer apellido).
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password' => $this->passwordRules(),
            'telefono' => ['nullable', 'string', 'max:30'],
            'curp' => ['nullable', 'string', 'max:18'],
            'rfc' => ['nullable', 'string', 'max:13'],
            'nss' => ['nullable', 'string', 'max:11'],
            'fecha_nacimiento' => ['nullable', 'date'],
            // Texto único (APKs anteriores) y, opcionales, los campos
            // estructurados del domicilio (DomicilioService).
            'direccion' => ['nullable', 'string', 'max:255'],
            ...app(DomicilioService::class)->reglas(),
            'contacto_emergencia_nombre' => ['nullable', 'string', 'max:150'],
            'contacto_emergencia_telefono' => ['nullable', 'string', 'max:30'],
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
