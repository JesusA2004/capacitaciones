<?php

namespace App\Services;

use App\Models\Colaborador;
use App\Models\Departamento;
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
 *
 * Los datos de alcance (sucursal, departamento, puesto, jefe) viven en
 * App\Models\Colaborador, no en User — todo método que antes leía estas
 * columnas directamente de un User ahora pasa por `$usuario->colaborador`.
 * Un usuario sin colaborador enlazado (no debería pasar tras el backfill,
 * pero se maneja de forma defensiva) no ve nada fuera de sí mismo.
 */
class AlcanceOrganizacionalService
{
    /**
     * Roles con acceso a toda la organizacion, sin restriccion de sucursal.
     * rh_admin/rh_auxiliar se agregaron con el Portal RH: el personal de RH
     * opera sobre toda la organizacion, no solo su propia sucursal (a
     * diferencia de gerente_sucursal). director_comercial ve reportes
     * globales, sin autoridad administrativa. Ver docs/ROLES_PERMISOS_RH.md.
     *
     * @var array<int, string>
     */
    private const ROLES_ALCANCE_GLOBAL = ['super_admin', 'administrador_capacitacion', 'auditor', 'rh_admin', 'rh_auxiliar', 'director_comercial', 'direccion', 'juridico', 'sistemas'];

    /**
     * Roles restringidos a sus sucursales autorizadas (principal + adicionales
     * via sucursal_colaborador). gerente_regional/coordinadora_regional ven varias
     * sucursales asignando varias sucursales adicionales al colaborador — el
     * mismo mecanismo que gerente_sucursal, sin lógica nueva: el alcance
     * real depende de qué sucursales tenga asignadas cada colaborador, no del
     * nombre del rol.
     *
     * @var array<int, string>
     */
    private const ROLES_ALCANCE_SUCURSAL = [
        'gerente_sucursal', 'supervisor',
        'gerente_regional', 'gerente', 'subgerente',
        'coordinadora_regional', 'coordinadora',
    ];

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

        $colaborador = $usuario->colaborador;

        if ($colaborador === null) {
            return collect();
        }

        return collect([$colaborador->sucursal_principal_id])
            ->merge($colaborador->sucursalesAdicionales()->pluck('sucursales.id'))
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * IDs de departamentos con al menos un colaborador dentro del alcance de
     * $usuario (alcance global => todos los departamentos existentes).
     * `departamentos` no tiene columna de sucursal propia (es un catalogo
     * compartido entre sucursales, ver App\Models\Departamento), asi que su
     * alcance se deriva de los colaboradores visibles, no de una relacion
     * directa. Usado para acotar las opciones de filtro que se le ofrecen a
     * un usuario sin alcance global (p. ej. Rh\CumpleanosController) — nunca
     * debe verse un departamento fuera de su alcance en un selector.
     *
     * @return Collection<int, int>
     */
    public function departamentosVisiblesIds(User $usuario): Collection
    {
        if ($this->tieneAlcanceGlobal($usuario)) {
            return Departamento::query()->pluck('id');
        }

        return $this->limitarColaboradoresPorAlcance(Colaborador::query(), $usuario)
            ->whereNotNull('departamento_id')
            ->distinct()
            ->pluck('departamento_id');
    }

    /**
     * Acota una consulta de `colaboradores` (expedientes, directorio,
     * organigrama...) al alcance de $usuario.
     *
     * @param  Builder<Colaborador>  $query
     * @return Builder<Colaborador>
     */
    public function limitarColaboradoresPorAlcance(Builder $query, User $usuario): Builder
    {
        if ($this->tieneAlcanceGlobal($usuario)) {
            return $query;
        }

        if ($this->tieneAlcanceDeSucursal($usuario)) {
            return $query->whereIn('sucursal_principal_id', $this->sucursalesVisiblesIds($usuario));
        }

        if ($usuario->hasRole('jefe_directo')) {
            return $query->where('jefe_id', $usuario->colaborador_id);
        }

        return $query->where('id', $usuario->colaborador_id);
    }

    /**
     * Acota una consulta de `users` (cuentas de acceso, pantalla
     * Administración > Usuarios) al alcance de $usuario, uniendo por su
     * colaborador.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function limitarUsuariosPorAlcance(Builder $query, User $usuario): Builder
    {
        if ($this->tieneAlcanceGlobal($usuario)) {
            return $query;
        }

        if ($this->tieneAlcanceDeSucursal($usuario)) {
            $sucursalesVisibles = $this->sucursalesVisiblesIds($usuario);

            return $query->whereHas('colaborador', function (Builder $sub) use ($sucursalesVisibles): void {
                $sub->whereIn('sucursal_principal_id', $sucursalesVisibles);
            });
        }

        if ($usuario->hasRole('jefe_directo')) {
            $colaboradorId = $usuario->colaborador_id;

            return $query->whereHas('colaborador', function (Builder $sub) use ($colaboradorId): void {
                $sub->where('jefe_id', $colaboradorId);
            });
        }

        return $query->where('id', $usuario->id);
    }

    public function puedeVerUsuario(User $usuario, User $objetivo): bool
    {
        if ($this->tieneAlcanceGlobal($usuario) || $usuario->is($objetivo)) {
            return true;
        }

        $colaboradorObjetivo = $objetivo->colaborador;

        if ($this->tieneAlcanceDeSucursal($usuario)) {
            return $colaboradorObjetivo !== null
                && $colaboradorObjetivo->sucursal_principal_id !== null
                && $this->sucursalesVisiblesIds($usuario)->contains($colaboradorObjetivo->sucursal_principal_id);
        }

        if ($usuario->hasRole('jefe_directo')) {
            return $colaboradorObjetivo !== null && $colaboradorObjetivo->jefe_id === $usuario->colaborador_id;
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
     *
     * Nota: $colaborador aquí es el alumno/aprendiz del módulo de
     * capacitación (siempre un `User`, cuenta con login) — no confundir con
     * App\Models\Colaborador. Ver Parte B en el plan de separación
     * Usuario/Colaborador.
     */
    public function puedeRevisarColaborador(User $revisor, User $colaborador): bool
    {
        if ($this->tieneAlcanceDeSucursal($revisor)) {
            return $this->puedeVerUsuario($revisor, $colaborador);
        }

        return true;
    }

    /**
     * IDs de colaboradores (cuentas User, alumnos) que un revisor con
     * alcance de sucursal puede calificar; `null` significa "sin
     * restricción" (alcance global o sin alcance organizacional definido,
     * como el rol instructor).
     *
     * @return Collection<int, int>|null
     */
    public function idsColaboradoresParaRevision(User $revisor): ?Collection
    {
        if (! $this->tieneAlcanceDeSucursal($revisor)) {
            return null;
        }

        $sucursalesVisibles = $this->sucursalesVisiblesIds($revisor);

        return User::query()
            ->whereHas('colaborador', function (Builder $sub) use ($sucursalesVisibles): void {
                $sub->whereIn('sucursal_principal_id', $sucursalesVisibles);
            })
            ->pluck('id');
    }

    /**
     * Acota la consulta de colaboradores para el explorador de expedientes
     * (Portal RH). Reutiliza el mismo criterio de alcance de
     * limitarColaboradoresPorAlcance() (incluye jefe_directo via jefe_id), pero
     * exige ademas el permiso especifico de expedientes: alguien con
     * alcance global que no tenga `expedientes.ver_todos` no debe ver el
     * expediente de nadie mas que el suyo (p. ej. auditor con auditoria.ver
     * pero sin expedientes.* explicito, si se reconfigura desde Administracion > Roles).
     *
     * @param  Builder<Colaborador>  $query
     * @return Builder<Colaborador>
     */
    public function limitarExpedientesPorAlcance(Builder $query, User $usuario): Builder
    {
        if ($usuario->can('expedientes.ver_todos') || $usuario->can('expedientes.ver_sucursal')) {
            return $this->limitarColaboradoresPorAlcance($query, $usuario);
        }

        return $query->where('id', $usuario->colaborador_id);
    }

    /**
     * Acota por sucursal cualquier consulta de un modelo que tenga una
     * columna de sucursal (vacantes, candidatos, etc.). Quien tiene alcance
     * global no se restringe; el resto solo ve registros de sus sucursales
     * visibles (principal + adicionales). Los registros sin sucursal
     * asignada (`null`) se consideran visibles para todos, ya que no
     * pertenecen a ninguna sucursal en particular.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function limitarPorSucursal(Builder $query, User $usuario, string $columnaSucursal = 'sucursal_id'): Builder
    {
        if ($this->tieneAlcanceGlobal($usuario)) {
            return $query;
        }

        $sucursalesVisibles = $this->sucursalesVisiblesIds($usuario);

        return $query->where(function (Builder $sub) use ($columnaSucursal, $sucursalesVisibles): void {
            $sub->whereIn($columnaSucursal, $sucursalesVisibles)
                ->orWhereNull($columnaSucursal);
        });
    }

    /**
     * Igual criterio que limitarExpedientesPorAlcance(), pero para un
     * colaborador ya cargado en memoria (vista de expediente individual).
     */
    /**
     * Alcance organizacional puro (sin revisar permisos): true si el
     * colaborador cae dentro de lo que este usuario administra — global,
     * sus sucursales, o sus subordinados directos si es jefe_directo — o si
     * es él mismo. Las Policies combinan esto con el permiso específico de
     * cada acción; nunca se usa solo como autorización.
     */
    public function alcanzaColaborador(User $usuario, Colaborador $colaborador): bool
    {
        if ($usuario->colaborador_id === $colaborador->id || $this->tieneAlcanceGlobal($usuario)) {
            return true;
        }

        if ($this->tieneAlcanceDeSucursal($usuario)) {
            return $colaborador->sucursal_principal_id !== null
                && $this->sucursalesVisiblesIds($usuario)->contains($colaborador->sucursal_principal_id);
        }

        if ($usuario->hasRole('jefe_directo') && $usuario->colaborador_id !== null) {
            return $colaborador->jefe_id === $usuario->colaborador_id || $colaborador->gerente_id === $usuario->colaborador_id;
        }

        return false;
    }

    public function puedeVerExpediente(User $usuario, Colaborador $colaborador): bool
    {
        if ($usuario->colaborador_id === $colaborador->id) {
            return $usuario->can('expedientes.ver');
        }

        if (! $usuario->can('expedientes.ver_todos') && ! $usuario->can('expedientes.ver_sucursal')) {
            return false;
        }

        if ($this->tieneAlcanceGlobal($usuario)) {
            return true;
        }

        if ($this->tieneAlcanceDeSucursal($usuario)) {
            return $colaborador->sucursal_principal_id !== null
                && $this->sucursalesVisiblesIds($usuario)->contains($colaborador->sucursal_principal_id);
        }

        if ($usuario->hasRole('jefe_directo')) {
            return $colaborador->jefe_id === $usuario->colaborador_id;
        }

        return false;
    }
}
