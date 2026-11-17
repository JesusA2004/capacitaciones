<?php

namespace App\Http\Requests\Reclutamiento;

use Illuminate\Foundation\Http\FormRequest;

/** Cancelar una cita de la agenda (web y API, mismas reglas y mensajes). */
class CancelarAgendaRequest extends FormRequest
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
        return ['motivo' => ['required', 'string', 'max:500']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['motivo.required' => 'Indica el motivo de la cancelación.'];
    }
}
