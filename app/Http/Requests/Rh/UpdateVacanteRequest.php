<?php

namespace App\Http\Requests\Rh;

use App\Enums\MotivoVacante;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateVacanteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('vacante')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'empresa_id' => ['nullable', 'integer', 'exists:empresas,id'],
            'sucursal_id' => ['nullable', 'integer', 'exists:sucursales,id'],
            'departamento_id' => ['nullable', 'integer', 'exists:departamentos,id'],
            'puesto_id' => ['nullable', 'integer', 'exists:puestos,id'],
            'gerente_solicitante_id' => ['nullable', 'integer', 'exists:users,id'],
            'responsable_rh_id' => ['nullable', 'integer', 'exists:users,id'],
            'motivo' => ['required', new Enum(MotivoVacante::class)],
            'fecha_apertura' => ['required', 'date'],
            'fecha_estimada_cobertura' => ['nullable', 'date', 'after_or_equal:fecha_apertura'],
            'observaciones' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
