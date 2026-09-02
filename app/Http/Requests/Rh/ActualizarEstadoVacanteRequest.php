<?php

namespace App\Http\Requests\Rh;

use App\Enums\EstadoVacante;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class ActualizarEstadoVacanteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cambiarEstado', $this->route('vacante')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'estado' => ['required', new Enum(EstadoVacante::class)],
        ];
    }
}
