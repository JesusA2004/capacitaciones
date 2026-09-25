<?php

namespace App\Http\Requests\Administracion;

use App\Enums\MotivoCobertura;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Asignar quién cubre temporalmente un puesto de otra sucursal/región desde
 * el organigrama. Mismo permiso que editar la jerarquía de puestos.
 */
class StoreCoberturaPuestoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $usuario = $this->user();

        return $usuario !== null && ($usuario->can('organigrama.editar') || $usuario->can('puestos.administrar'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'colaborador_id' => ['required', 'integer', Rule::exists('colaboradores', 'id')],
            'puesto_id' => ['required', 'integer', Rule::exists('puestos', 'id')->where('activo', true)],
            'sucursal_id' => ['nullable', 'integer', Rule::exists('sucursales', 'id'), 'required_without:region_id'],
            'region_id' => ['nullable', 'integer', Rule::exists('nodos_comerciales', 'id'), 'required_without:sucursal_id'],
            'motivo' => ['required', Rule::enum(MotivoCobertura::class)],
            'nota' => ['nullable', 'string', 'max:500'],
            'fecha_inicio' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'colaborador_id.required' => 'Elige a quién va a cubrir el puesto.',
            'motivo.required' => 'Indica el motivo de la cobertura.',
            'sucursal_id.required_without' => 'Indica la sucursal o la región que se va a cubrir.',
            'region_id.required_without' => 'Indica la sucursal o la región que se va a cubrir.',
        ];
    }
}
