<?php

namespace App\Http\Requests\Cuestionarios;

use App\Enums\TipoPregunta;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class StorePreguntaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('banco')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enunciado' => ['required', 'string', 'max:2000'],
            'tipo' => ['required', new Enum(TipoPregunta::class)],
            'puntos' => ['required', 'integer', 'min:1'],
            'explicacion' => ['nullable', 'string', 'max:2000'],
            'opciones' => [
                'array',
                Rule::requiredIf(fn () => $this->input('tipo') !== TipoPregunta::RespuestaCorta->value),
                'min:2',
            ],
            'opciones.*.texto' => ['required_with:opciones', 'string', 'max:250'],
            'opciones.*.es_correcta' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $tipo = $this->input('tipo');

            if ($tipo === TipoPregunta::RespuestaCorta->value) {
                return;
            }

            /** @var array<int, array{texto?: string, es_correcta?: bool}> $opciones */
            $opciones = $this->input('opciones', []);
            $correctas = collect($opciones)->filter(fn (array $opcion) => (bool) ($opcion['es_correcta'] ?? false));

            if ($correctas->isEmpty()) {
                $validator->errors()->add('opciones', 'Debes marcar al menos una opción como correcta.');
            } elseif ($tipo === TipoPregunta::OpcionUnica->value && $correctas->count() > 1) {
                $validator->errors()->add('opciones', 'Una pregunta de opción única solo puede tener una respuesta correcta.');
            }
        });
    }
}
