<?php

namespace App\Http\Requests\Solicitudes;

use App\Models\SolicitudInterna;
use App\Services\Expedientes\ActualizacionDatosService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Respuesta del colaborador cuando RH pidió más información sobre su
 * corrección (web y API móvil, mismo request). `datos` opcional: los
 * valores propuestos corregidos (solo en actualización de datos), con las
 * mismas reglas que al crear la solicitud.
 */
class ResponderCorreccionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $solicitud = $this->route('solicitud');

        return $solicitud instanceof SolicitudInterna && $this->user()?->can('responderCorreccion', $solicitud) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mensaje' => ['required', 'string', 'min:3', 'max:1000'],
            ...app(ActualizacionDatosService::class)->reglas(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mensaje.required' => 'Escribe tu respuesta para Recursos Humanos.',
            'mensaje.min' => 'Escribe tu respuesta para Recursos Humanos.',
            'mensaje.max' => 'La respuesta no puede pasar de 1,000 caracteres.',
            ...app(ActualizacionDatosService::class)->mensajes(),
        ];
    }
}
