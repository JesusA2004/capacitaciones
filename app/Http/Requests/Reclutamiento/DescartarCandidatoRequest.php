<?php

namespace App\Http\Requests\Reclutamiento;

use App\Enums\EstadoCandidato;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DescartarCandidatoRequest extends FormRequest
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
            'estado' => ['required', Rule::in([
                EstadoCandidato::NoViable->value,
                EstadoCandidato::NoSeleccionado->value,
                EstadoCandidato::NoRespondio->value,
                EstadoCandidato::Desistio->value,
            ])],
            'motivo' => ['required', 'string', 'max:2000'],
        ];
    }
}
