<?php

namespace App\Http\Requests\Administracion;

use App\Models\Sucursal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * «Plantilla autorizada» desde la ficha del puesto: cuántas plazas tiene en
 * una ubicación (sucursal o Corporativo). Solo RH — mismo permiso que en el
 * detalle de sucursal (SucursalPolicy::editarPlantilla, headcount.editar) —
 * y con motivo obligatorio para la bitácora.
 */
class ActualizarPlantillaPuestoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $sucursal = Sucursal::query()->whereKey($this->integer('sucursal_id'))->first();

        // Sin ubicación válida, la validación responde el error en el campo.
        return $sucursal === null
            ? ($this->user()?->can('headcount.editar') ?? false)
            : ($this->user()?->can('editarPlantilla', $sucursal) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sucursal_id' => ['required', 'integer', Rule::exists('sucursales', 'id')->where('activo', true)],
            'plantilla_autorizada' => ['required', 'integer', 'min:0', 'max:999'],
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'sucursal_id' => 'ubicación',
            'plantilla_autorizada' => 'plazas autorizadas',
            'motivo' => 'motivo del cambio',
        ];
    }
}
