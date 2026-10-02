<?php

namespace App\Http\Requests\Administracion;

use App\Models\Sucursal;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Captura de plantilla autorizada de un puesto en una sucursal — solo RH
 * (SucursalPolicy::editarPlantilla). El motivo es obligatorio: queda en el
 * histórico junto con quién hizo el cambio.
 */
class ActualizarPlantillaAutorizadaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $sucursal = $this->route('sucursal');

        return $sucursal instanceof Sucursal && ($this->user()?->can('editarPlantilla', $sucursal) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
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
            'plantilla_autorizada' => 'plantilla autorizada',
            'motivo' => 'motivo del cambio',
        ];
    }
}
