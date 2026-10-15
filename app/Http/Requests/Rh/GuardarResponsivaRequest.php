<?php

namespace App\Http\Requests\Rh;

use App\Enums\CondicionActivoResponsiva;
use App\Enums\TipoResponsiva;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Captura de una responsiva de equipo (vista previa y generación). La
 * autorización (gerente con alcance o RH) la aplica ResponsivaPolicy en el
 * controlador.
 */
class GuardarResponsivaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::enum(TipoResponsiva::class)],
            'lugar' => ['required', 'string', 'max:160'],
            'fecha_entrega' => ['required', 'date', 'before_or_equal:today'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'activos' => ['required', 'array', 'min:1', 'max:20'],
            'activos.*.descripcion' => ['required', 'string', 'max:160'],
            'activos.*.marca' => ['nullable', 'string', 'max:120'],
            'activos.*.modelo' => ['nullable', 'string', 'max:120'],
            'activos.*.numero_serie' => ['nullable', 'string', 'max:120'],
            'activos.*.color' => ['nullable', 'string', 'max:60'],
            'activos.*.cantidad' => ['required', 'integer', 'min:1', 'max:999'],
            'activos.*.estado' => ['required', Rule::enum(CondicionActivoResponsiva::class)],
            'activos.*.caracteristicas' => ['nullable', 'string', 'max:2000'],
            'activos.*.accesorios' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tipo' => 'tipo de responsiva',
            'lugar' => 'lugar',
            'fecha_entrega' => 'fecha de entrega',
            'activos' => 'equipos entregados',
            'activos.*.descripcion' => 'descripción del equipo',
            'activos.*.marca' => 'marca',
            'activos.*.modelo' => 'modelo',
            'activos.*.numero_serie' => 'número de serie',
            'activos.*.color' => 'color',
            'activos.*.cantidad' => 'cantidad',
            'activos.*.estado' => 'estado del equipo',
            'activos.*.caracteristicas' => 'características',
            'activos.*.accesorios' => 'accesorios',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'activos.required' => 'Agrega al menos un equipo a la responsiva.',
            'activos.min' => 'Agrega al menos un equipo a la responsiva.',
            'fecha_entrega.before_or_equal' => 'La fecha de entrega no puede ser futura.',
        ];
    }
}
