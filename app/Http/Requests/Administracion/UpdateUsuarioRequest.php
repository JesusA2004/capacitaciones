<?php

namespace App\Http\Requests\Administracion;

use App\Enums\EstadoUsuario;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateUsuarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('usuario')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'apellidos' => ['nullable', 'string', 'max:150'],
            'numero_empleado' => ['nullable', 'string', 'max:30', Rule::unique('users', 'numero_empleado')->ignore($this->route('usuario'))],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('usuario'))],
            'telefono' => ['nullable', 'string', 'max:30'],
            'sucursal_principal_id' => ['required', 'integer', 'exists:sucursales,id'],
            'sucursales_adicionales' => ['array'],
            'sucursales_adicionales.*' => ['integer', 'exists:sucursales,id'],
            'departamento_id' => ['nullable', 'integer', 'exists:departamentos,id'],
            'puesto_id' => ['nullable', 'integer', 'exists:puestos,id'],
            'jefe_id' => ['nullable', 'integer', 'exists:users,id'],
            'fecha_ingreso' => ['nullable', 'date'],
            'estatus' => ['nullable', new Enum(EstadoUsuario::class)],
            'zona_horaria' => ['nullable', 'string', 'max:60'],
            'roles' => ['array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'sucursal_principal_id' => 'sucursal principal',
        ];
    }
}
