<?php

namespace App\Http\Requests\Administracion;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSucursalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('sucursal')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'empresa_id' => ['required', 'integer', 'exists:empresas,id'],
            'nombre' => ['required', 'string', 'max:150'],
            'clave' => ['required', 'string', 'max:20', Rule::unique('sucursales', 'clave')->ignore($this->route('sucursal'))],
            'direccion' => ['nullable', 'string', 'max:255'],
            'ciudad' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', 'string', 'max:100'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'responsable_id' => ['nullable', 'integer', 'exists:users,id'],
            'activo' => ['boolean'],
        ];
    }
}
