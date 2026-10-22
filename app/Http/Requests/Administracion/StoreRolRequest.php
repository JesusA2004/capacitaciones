<?php

namespace App\Http\Requests\Administracion;

use App\Services\Permisos\CatalogoPermisosService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * Crear un rol: los permisos se validan contra el catálogo
 * (CatalogoPermisosService::asignables).
 */
class StoreRolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Role::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombre' => [
                'required',
                'string',
                'max:100',
                Rule::unique('roles', 'name')->where('guard_name', 'web'),
            ],
            'permisos' => ['array'],
            'permisos.*' => ['string', 'distinct', Rule::in(app(CatalogoPermisosService::class)->asignables())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'permisos.*.in' => 'El permiso «:input» no existe en el catálogo de permisos.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nombre' => 'nombre del rol',
            'permisos' => 'permisos',
        ];
    }
}
