<?php

namespace App\Http\Requests\Reclutamiento;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pregunta de requisito indispensable al registrar candidatos
 * (App\Services\Reclutamiento\PreguntasFiltroCandidatoService).
 */
class GuardarPreguntaFiltroCandidatoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can('configuracion.rh') && $this->user()->can('configuracion.rh.reclutamiento');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'pregunta' => ['required', 'string', 'max:200'],
            // Vacío = aplica a todos los puestos.
            'puestos' => ['nullable', 'array'],
            'puestos.*' => ['integer', 'exists:puestos,id'],
            'respuesta_requerida' => ['required', 'boolean'],
            'eliminatoria' => ['boolean'],
            'motivo_rechazo_id' => ['nullable', 'integer', 'exists:motivos_rechazo_candidato,id'],
            'activo' => ['boolean'],
            'orden' => ['nullable', 'integer', 'min:0', 'max:999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pregunta.required' => 'Escribe la pregunta (p. ej. «¿Sabe manejar moto?»).',
            'respuesta_requerida.required' => 'Indica qué respuesta exige el puesto (Sí o No).',
        ];
    }
}
