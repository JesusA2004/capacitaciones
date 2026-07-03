<?php

namespace App\Http\Requests\Reuniones;

use App\Enums\EstadoAsistencia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class MarcarAsistenciaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('sesiones.administrar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'estado' => ['required', new Enum(EstadoAsistencia::class)],
            'motivo' => ['nullable', 'string', 'max:1000'],
            'minutos' => ['nullable', 'integer', 'min:0'],
            'evidencia' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp'],
        ];
    }
}
