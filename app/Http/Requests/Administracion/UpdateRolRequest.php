<?php

namespace App\Http\Requests\Administracion;

use App\Services\Permisos\CatalogoPermisosService;
use App\Services\RolPermisoService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * Guardar un rol desde «Roles y permisos». Los permisos se validan contra
 * el catálogo (CatalogoPermisosService::asignables): no se puede asignar un
 * permiso inventado ni uno que no exista en la base. Un permiso fuera del
 * catálogo que el rol YA tenía (legado) se puede conservar, no agregar.
 */
class UpdateRolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('rol')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rol = $this->route('rol');
        $actuales = $rol instanceof Role ? $rol->permissions()->pluck('name')->map(fn ($p): string => (string) $p)->all() : [];
        $esDelSistema = $rol instanceof Role && app(RolPermisoService::class)->esRolDelSistema($rol);
        $nombreActual = $rol instanceof Role ? (string) $rol->name : null;

        return [
            'nombre' => [
                'required',
                'string',
                'max:100',
                Rule::unique('roles', 'name')->where('guard_name', 'web')->ignore($rol),
                function (string $atributo, mixed $valor, Closure $fallar) use ($nombreActual, $esDelSistema): void {
                    if ($esDelSistema && $valor !== $nombreActual) {
                        $fallar('Los roles del sistema no se pueden renombrar; clónalo para crear uno con otro nombre.');
                    }
                },
            ],
            'permisos' => ['array'],
            'permisos.*' => ['string', 'distinct', Rule::in(array_values(array_unique([...app(CatalogoPermisosService::class)->asignables(), ...$actuales])))],
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
