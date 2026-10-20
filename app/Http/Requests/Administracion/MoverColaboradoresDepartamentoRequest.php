<?php

namespace App\Http\Requests\Administracion;

use App\Models\Departamento;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * «Departamentos → mover personas»: a qué departamento y a quiénes. Quién
 * puede hacerlo lo decide DepartamentoPolicy::moverColaboradores(); el
 * filtrado por persona (alcance, uno mismo, ya movidos) vive en
 * MovimientoDepartamentoService.
 */
class MoverColaboradoresDepartamentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('moverColaboradores', $this->route('departamento')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $origen = $this->route('departamento');
        $origenId = $origen instanceof Departamento ? $origen->id : 0;

        return [
            'departamento_destino_id' => [
                'required',
                'integer',
                Rule::exists('departamentos', 'id')->where('activo', true)->whereNull('deleted_at'),
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
            'departamento_destino_id.required' => 'Elige el departamento destino.',
            'departamento_destino_id.exists' => 'El departamento destino no existe o está inactivo.',
            'departamento_destino_id.not_in' => 'El destino debe ser un departamento distinto al de origen.',
            'colaborador_ids.required' => 'Selecciona al menos a una persona.',
            'colaborador_ids.min' => 'Selecciona al menos a una persona.',
        ];
    }
}
