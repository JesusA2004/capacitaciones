<?php

namespace App\Http\Requests\MiCapacitacion;

use App\Models\IntentoCuestionario;
use Illuminate\Foundation\Http\FormRequest;

class EnviarIntentoCuestionarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        $intento = $this->route('intento');

        return $intento instanceof IntentoCuestionario && $this->user()?->id === $intento->user_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'respuestas' => ['array'],
            'respuestas.*.pregunta_id' => ['required', 'integer', 'exists:preguntas,id'],
            'respuestas.*.opcion_pregunta_id' => ['nullable', 'integer', 'exists:opciones_pregunta,id'],
            'respuestas.*.opciones_seleccionadas' => ['nullable', 'array'],
            'respuestas.*.opciones_seleccionadas.*' => ['integer', 'exists:opciones_pregunta,id'],
            'respuestas.*.respuesta_texto' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
