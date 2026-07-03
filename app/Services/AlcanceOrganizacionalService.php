<?php

namespace App\Services;

use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Centraliza el calculo de que sucursales/colaboradores puede consultar
 * cada usuario, para aplicar el mismo criterio en Policies y en el scoping
 * de las consultas de los controladores administrativos. La autorizacion
 * real siempre vive en el backend; el frontend solo oculta accesos como
 * complemento de UX.
 */
class AlcanceOrganizacionalService
{
    /**
     * Roles con acceso a toda la organizacion, sin restriccion de sucursal.
     *
     * @var array<int, string>
     */
    private const ROLES_ALCANCE_GLOBAL = ['super_admin', 'administrador_capacitacion', 'auditor'];

    /**
     * Roles restringidos a sus sucursales autorizadas (principal + adicionales).
     *
     * @var array<int, string>
     */
    private const ROLES_ALCANCE_SUCURSAL = ['gerente_sucursal', 'supervisor'];

    public function tieneAlcanceGlobal(User $usuario): bool
    {
        return $usuario->hasAnyRole(self::ROLES_ALCANCE_GLOBAL);
    }

    public function tieneAlcanceDeSucursal(User $usuario): bool
    {
        return $usuario->hasAnyRole(self::ROLES_ALCANCE_SUCURSAL);
    }

    /**
     * @return Collection<int, int>
     */
    public function sucursalesVisiblesIds(User $usuario): Collection
    {
        if ($this->tieneAlcanceGlobal($usuario)) {
            return Sucursal::query()->pluck('id');
        }

        return collect([$usuario->sucursal_principal_id])
            ->merge($usuario->sucursalesAdicionales()->pluck('sucursales.id'))
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function limitarUsuariosPorAlcance(Builder $query, User $usuario): Builder
    {
        if ($this->tieneAlcanceGlobal($usuario)) {
            return $query;
        }

        if ($this->tieneAlcanceDeSucursal($usuario)) {
            return $query->whereIn('sucursal_principal_id', $this->sucursalesVisiblesIds($usuario));
        }

        return $query->where('id', $usuario->id);
    }

    public function puedeVerUsuario(User $usuario, User $objetivo): bool
    {
        if ($this->tieneAlcanceGlobal($usuario) || $usuario->is($objetivo)) {
            return true;
        }

        if ($this->tieneAlcanceDeSucursal($usuario)) {
            return $objetivo->sucursal_principal_id !== null
                && $this->sucursalesVisiblesIds($usuario)->contains($objetivo->sucursal_principal_id);
        }

        return false;
    }
}
