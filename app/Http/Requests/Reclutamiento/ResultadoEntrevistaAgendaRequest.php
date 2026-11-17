<?php

namespace App\Http\Requests\Reclutamiento;

use App\Enums\MotivoNoViableAgenda;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Resultado de una entrevista de la agenda (web y API, mismas reglas y
 * mensajes). «No viable» exige un motivo del catálogo y un comentario; la
 * autorización por cita la decide AgendaEventoService (Policy).
 */
class ResultadoEntrevistaAgendaRequest extends FormRequest
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
            'resultado' => ['required', 'in:viable,no_viable'],
            'motivo' => ['nullable', 'required_if:resultado,no_viable', 'string', Rule::enum(MotivoNoViableAgenda::class)],
            'comentario' => ['nullable', 'required_if:resultado,no_viable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.required_if' => 'Elige el motivo.',
            'motivo.enum' => 'Elige un motivo de la lista.',
            'comentario.required_if' => 'Escribe un comentario breve.',
        ];
    }
}
