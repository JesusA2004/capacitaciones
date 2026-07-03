<?php

namespace App\Http\Requests\Asignaciones;

use App\Enums\TipoDestinoAsignacion;
use App\Models\Asignacion;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreAsignacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Asignacion::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:180'],
            'curso_id' => ['required', 'integer', 'exists:cursos,id'],
            'fecha_inicio' => ['nullable', 'date'],
            'fecha_limite' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'obligatoria' => ['boolean'],
            'destinos' => ['required', 'array', 'min:1'],
            'destinos.*.tipo' => ['required', new Enum(TipoDestinoAsignacion::class)],
            'destinos.*.id' => ['nullable', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            foreach ($this->input('destinos', []) as $indice => $destino) {
                $tipo = $destino['tipo'] ?? null;
                $id = $destino['id'] ?? null;

                if ($tipo !== TipoDestinoAsignacion::Todos->value && empty($id)) {
                    $validator->errors()->add("destinos.{$indice}.id", 'Debes seleccionar un destino específico.');
                }
            }
        });
    }
}
