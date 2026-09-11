<?php

namespace App\Http\Requests\Rh;

use App\Enums\EstadoSolicitudInterna;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cambio de estado unificado del tablero Kanban de RH (drag and drop, ver
 * Rh\SolicitudController::actualizarEstado). La autorización real vive en el
 * controlador (distinto método de policy según el estado destino), esta
 * request solo valida la forma del payload.
 */
class ActualizarEstadoSolicitudInternaRequest extends FormRequest
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
            'estado' => [
                'required',
                Rule::in([
                    EstadoSolicitudInterna::EnRevision->value,
                    EstadoSolicitudInterna::RequiereCorreccion->value,
                    EstadoSolicitudInterna::Aprobada->value,
                    EstadoSolicitudInterna::Rechazada->value,
                    EstadoSolicitudInterna::Cerrada->value,
                ]),
            ],
            'comentario' => ['nullable', 'string', 'max:1000'],
            'motivo_rechazo' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
