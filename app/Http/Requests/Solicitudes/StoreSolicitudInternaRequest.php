<?php

namespace App\Http\Requests\Solicitudes;

use App\Enums\TipoSolicitudInterna;
use App\Models\SolicitudInterna;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSolicitudInternaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', SolicitudInterna::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tipo' => ['required', 'string', Rule::in(array_column(TipoSolicitudInterna::cases(), 'value'))],
            'motivo' => ['required', 'string', 'max:2000'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'fecha_inicio' => ['nullable', 'date'],
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
        ];
    }
}
