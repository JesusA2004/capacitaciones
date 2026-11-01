<?php

namespace App\Http\Requests\Correcciones;

/**
 * Forma de la confirmación. `motivo` llega opcional aquí a propósito: se exige en
 * CorreccionService para que ningún llamador lo evite y para responder primero 403.
 */
class AplicarCorreccionRequest extends VistaPreviaCorreccionRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'motivo' => ['nullable', 'string'],
            'huella' => ['required', 'string', 'size:64'],
            'huella_afectados' => ['nullable', 'string', 'size:64'],
            'clave_idempotencia' => ['required', 'uuid'],
        ];
    }
}
