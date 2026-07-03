<?php

namespace App\Http\Requests\Actividades;

use App\Enums\TipoEntregaActividad;
use App\Models\Actividad;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreActividadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Actividad::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'titulo' => ['required', 'string', 'max:180'],
            'instrucciones' => ['nullable', 'string', 'max:2000'],
            'tipo_entrega' => ['required', new Enum(TipoEntregaActividad::class)],
            'calificacion_minima' => ['required', 'integer', 'min:1', 'max:100'],
            'fecha_limite' => ['nullable', 'date'],
        ];
    }
}
