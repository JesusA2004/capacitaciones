<?php

namespace App\Http\Requests\Asignaciones;

use App\Enums\TipoDestinoAsignacion;
use App\Models\Asignacion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class PrevisualizarAsignacionRequest extends FormRequest
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
            'destinos' => ['required', 'array', 'min:1'],
            'destinos.*.tipo' => ['required', new Enum(TipoDestinoAsignacion::class)],
            'destinos.*.id' => ['nullable', 'integer'],
        ];
    }
}
