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

    /**
     * Igual que puedeVerUsuario(), pero pensado para quien revisa/califica
     * entregas y respuestas de otros colaboradores (instructor, quien tenga
     * `respuestas.calificar`/`respuestas.ver`). El rol `instructor` no está
     * en ninguna de las dos listas de alcance porque el modelo de datos no
     * asocia instructores a cursos/sucursales concretas (ver
     * docs/AUDITORIA_CUMPLIMIENTO.md sección 13): a falta de esa relación,
     * se le trata como alcance global para esta operación específica en vez
     * de bloquearlo por completo. Solo los roles con alcance de sucursal
     * explícito (gerente_sucursal, supervisor) quedan restringidos a sus
     * propias sucursales al revisar el trabajo de otros.
     */
    public function puedeRevisarColaborador(User $revisor, User $colaborador): bool
    {
        if ($this->tieneAlcanceDeSucursal($revisor)) {
            return $this->puedeVerUsuario($revisor, $colaborador);
        }

        return true;
    }

    /**
     * IDs de colaboradores que un revisor con alcance de sucursal puede
     * calificar; `null` significa "sin restricción" (alcance global o sin
     * alcance organizacional definido, como el rol instructor). Se usa con
     * `whereIn('user_id', ...)` en vez de `whereHas` para no depender de
     * closures tipadas sobre relaciones genéricas.
     *
     * @return Collection<int, int>|null
     */
    public function idsColaboradoresParaRevision(User $revisor): ?Collection
    {
        if (! $this->tieneAlcanceDeSucursal($revisor)) {
            return null;
        }

        return User::query()->whereIn('sucursal_principal_id', $this->sucursalesVisiblesIds($revisor))->pluck('id');
    }
}
