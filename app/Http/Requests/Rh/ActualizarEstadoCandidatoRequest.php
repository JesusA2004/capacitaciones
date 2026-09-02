<?php

namespace App\Http\Requests\Rh;

use App\Enums\EstadoCandidato;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class ActualizarEstadoCandidatoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $candidato = $this->route('candidato');
        $nuevoEstado = $this->enum('estado', EstadoCandidato::class);

        if ($nuevoEstado === null) {
            return false;
        }

        return $this->user()?->can('cambiarEstado', [$candidato, $nuevoEstado]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'estado' => ['required', new Enum(EstadoCandidato::class)],
            'nota' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
