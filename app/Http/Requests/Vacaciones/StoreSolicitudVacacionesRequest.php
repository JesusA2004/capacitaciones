<?php

namespace App\Http\Requests\Vacaciones;

use App\Models\SolicitudVacaciones;
use Illuminate\Foundation\Http\FormRequest;

class StoreSolicitudVacacionesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', SolicitudVacaciones::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fecha_inicio' => ['required', 'date', 'after_or_equal:today'],
            'fecha_fin' => ['required', 'date', 'after_or_equal:fecha_inicio'],
            'dias_solicitados' => ['required', 'integer', 'min:1', 'max:60'],
            'comentario' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
