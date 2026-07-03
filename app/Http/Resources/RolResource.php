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
            'permisos' => $this->permissions->pluck('name'),
            'usuarios_count' => $this->whenCounted('users'),
            'es_protegido' => $this->name === 'super_admin',
        ];
    }
}
