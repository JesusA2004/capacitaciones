<?php

namespace App\Http\Requests\Rh;

use Illuminate\Foundation\Http\FormRequest;

class RechazarSolicitudVacacionesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('rechazar', $this->route('solicitud')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'motivo_rechazo' => ['required', 'string', 'max:1000'],
        ];
    }
}
