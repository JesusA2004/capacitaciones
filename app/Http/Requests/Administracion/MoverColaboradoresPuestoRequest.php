<?php

namespace App\Http\Requests\Administracion;

use App\Models\Puesto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * «Puestos y salarios → trasladar personas»: a qué puesto y a quiénes. Quién
 * puede hacerlo lo decide PuestoPolicy::moverColaboradores(); el filtrado por
 * persona (alcance, uno mismo, ya trasladados) vive en MovimientoPuestoService.
 */
class MoverColaboradoresPuestoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('moverColaboradores', $this->route('puesto')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $origen = $this->route('puesto');
        $origenId = $origen instanceof Puesto ? $origen->id : 0;

        return [
            'puesto_destino_id' => [
                'required',
                'integer',
                Rule::exists('puestos', 'id')->where('activo', true)->whereNull('deleted_at'),
                Rule::notIn([$origenId]),
            ],
            'colaborador_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'colaborador_ids.*' => ['integer', 'distinct'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'puesto_destino_id.required' => 'Elige el puesto destino.',
            'puesto_destino_id.exists' => 'El puesto destino no existe o está inactivo.',
            'puesto_destino_id.not_in' => 'El destino debe ser un puesto distinto al de origen.',
            'colaborador_ids.required' => 'Selecciona al menos a una persona.',
            'colaborador_ids.min' => 'Selecciona al menos a una persona.',
        ];
    }
}
