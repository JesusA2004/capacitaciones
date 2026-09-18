<?php

namespace App\Http\Requests\Rh;

use App\Enums\CanalReclutamiento;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreCampanaReclutamientoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole(['rh_admin', 'super_admin']) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mes' => ['required', 'integer', 'min:1', 'max:12'],
            'anio' => ['required', 'integer', 'min:2000', 'max:2100'],
            'canal' => ['required', new Enum(CanalReclutamiento::class)],
            'empresa_id' => ['nullable', 'integer', 'exists:empresas,id'],
            'sucursal_id' => ['nullable', 'integer', 'exists:sucursales,id'],
            'departamento_id' => ['nullable', 'integer', 'exists:departamentos,id'],
            'puesto_id' => ['nullable', 'integer', 'exists:puestos,id'],
            'monto' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'candidatos_generados' => ['nullable', 'integer', 'min:0'],
            'observaciones' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
