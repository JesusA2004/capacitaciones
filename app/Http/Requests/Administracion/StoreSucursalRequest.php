<?php

namespace App\Http\Requests\Administracion;

use App\Models\Sucursal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSucursalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Sucursal::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:150'],
            'clave' => ['required', 'string', 'max:20', Rule::unique('sucursales', 'clave')],
            'direccion' => ['nullable', 'string', 'max:255'],
            'ciudad' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', 'string', 'max:100'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'responsable_id' => ['nullable', 'integer', 'exists:users,id'],
            'activo' => ['boolean'],
        ];
    }
}
