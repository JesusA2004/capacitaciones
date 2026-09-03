<?php

namespace App\Http\Requests\Rh;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Comentario opcional/obligatorio para acciones de revisión que no rechazan
 * la solicitud (marcar en revisión, requerir corrección, aprobar, cerrar).
 * La autorización real de cada acción vive en el controlador (distinto
 * método de policy según la acción), esta request solo valida el comentario.
 */
class ComentarioSolicitudInternaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'comentario' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
