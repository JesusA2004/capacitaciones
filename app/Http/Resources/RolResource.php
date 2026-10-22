<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Role;

/**
 * @mixin Role
 */
class RolResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->name,
            // RolPermisoService::rolesParaListado() ya trae los nombres sin
            // hidratar modelos Permission.
            'permisos' => $this->resource->getAttribute('permisos_nombres') ?? $this->permissions->pluck('name'),
            'usuarios_count' => $this->whenCounted('users'),
            // super_admin: bloqueado como «todo» (no se edita ni se elimina).
            'es_protegido' => $this->name === 'super_admin',
            // Rol del seeder: no se renombra ni se elimina; sus permisos sí.
            'es_del_sistema' => (bool) ($this->resource->getAttribute('es_del_sistema') ?? false),
        ];
    }
}
