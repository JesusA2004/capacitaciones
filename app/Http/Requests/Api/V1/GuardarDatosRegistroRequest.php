<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Expedientes\ActualizacionDatosService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Un paso del registro completo en la app (RegistroAppService): mismas
 * reglas de formato que la solicitud de actualización de datos (CURP, RFC,
 * NSS, teléfono, domicilio estructurado…), más los datos médicos opcionales.
 * Que el registro siga abierto lo decide el servicio.
 */
class GuardarDatosRegistroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->colaborador !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...app(ActualizacionDatosService::class)->reglas(),
            'datos_medicos' => ['nullable', 'array:condicion_medica,alergias'],
            'datos_medicos.condicion_medica' => ['nullable', 'string', 'max:1000'],
            'datos_medicos.alergias' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...app(ActualizacionDatosService::class)->mensajes(),
            'datos_medicos.*.max' => 'Escribe como máximo 1000 caracteres.',
        ];
    }
}
