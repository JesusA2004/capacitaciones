<?php

namespace App\Http\Requests\Reclutamiento;

use App\Enums\ResultadoReferencia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegistrarReferenciaRequest extends FormRequest
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
            'empresa' => ['required', 'string', 'max:190'],
            'contacto' => ['required', 'string', 'max:190'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'relacion_puesto' => ['nullable', 'string', 'max:190'],
            'resultado' => ['required', Rule::enum(ResultadoReferencia::class)],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'fecha_validacion' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }
}
