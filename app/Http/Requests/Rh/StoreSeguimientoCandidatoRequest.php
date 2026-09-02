<?php

namespace App\Http\Requests\Rh;

use App\Enums\TipoSeguimientoCandidato;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreSeguimientoCandidatoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('candidato')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tipo' => ['required', new Enum(TipoSeguimientoCandidato::class)],
            'nota' => ['required', 'string', 'max:2000'],
            'fecha' => ['nullable', 'date'],
        ];
    }
}
