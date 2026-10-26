<?php

namespace App\Services\Permisos;

use App\Enums\AlcanceDatos;
use App\Enums\TipoNodoComercial;
use App\Models\Colaborador;
use App\Models\NodoComercial;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Permission\PermissionRegistrar;

/**
 * ¿QUÉ registros de un módulo puede ver este usuario? (Propios, Equipo,
 * Sucursal, Región, Todos), según los permisos de alcance de
 * config/permisos_modulos.php que tenga por sus roles — configurables en
 * «Roles y permisos», nunca por nombre de rol en un controller.
 *
 * Orden de evaluación en cada pantalla: primero el permiso de ACCESO o de
 * ACCIÓN (Policy / middleware), después este alcance sobre los datos. El
 * alcance nunca otorga una acción: ver todas las solicitudes no permite
 * aprobarlas.
 *
 * Compatibilidad: mientras un módulo no tenga todavía sus permisos de
 * alcance creados en la base (producción antes de
 * `people:sincronizar-permisos`), se usa el alcance histórico por rol de
 * AlcanceOrganizacionalService, para que nadie pierda ni gane visibilidad
 * por el despliegue.
 */
class AlcanceModuloService
{
    public function __construct(
        private readonly AlcanceOrganizacionalService $organizacional,
        private readonly PermissionRegistrar $registrar,
    ) {}

    public function nivel(User $usuario, string $modulo): AlcanceDatos
    {
        $alcances = $this->alcancesDe($modulo);

        if ($alcances === [] || ! $this->existenEnBase($alcances)) {
            $acceso = $this->modulos()[$modulo]['acceso'] ?? '';

            // Sin acceso al módulo, el alcance histórico por rol no aplica.
            return $acceso !== '' && ! $usuario->can($acceso) ? AlcanceDatos::Propios : $this->nivelHistorico($usuario);
        }

        foreach (AlcanceDatos::deMayorAMenor() as $nivel) {
            $permiso = $alcances[$nivel->value] ?? null;

            if ($permiso !== null && $usuario->can($permiso)) {
                return $nivel;
            }
        }

        return AlcanceDatos::Propios;
    }

    /**
     * Acota una consulta de `colaboradores` al alcance del módulo.
     *
     * @param  Builder<Colaborador>  $query
     * @return Builder<Colaborador>
     */
    public function limitarColaboradores(Builder $query, User $usuario, string $modulo): Builder
    {
        return match ($this->nivel($usuario, $modulo)) {
            AlcanceDatos::Todos => $query,
            AlcanceDatos::Region, AlcanceDatos::Sucursal => $query->where(fn (Builder $q) => $q
                ->whereIn('sucursal_principal_id', $this->sucursalesPara($usuario, $this->nivel($usuario, $modulo))->all())
                ->orWhere('id', $usuario->colaborador_id ?? 0)),
            AlcanceDatos::Equipo => $query->where(fn (Builder $q) => $q
                ->where('id', $usuario->colaborador_id ?? 0)
                ->orWhere(fn (Builder $e) => $e->whereNotNull('jefe_id')->where('jefe_id', $usuario->colaborador_id ?? 0))
                ->orWhere(fn (Builder $e) => $e->whereNotNull('gerente_id')->where('gerente_id', $usuario->colaborador_id ?? 0))),
            AlcanceDatos::Propios => $query->where('id', $usuario->colaborador_id ?? 0),
        };
    }

    /**
     * Acota cualquier consulta con una columna de colaborador (solicitudes,
     * actas, contratos, recibos…). Alcance «todos» no agrega condición.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function limitarPorColaborador(Builder $query, User $usuario, string $modulo, string $columna = 'colaborador_id', bool $conEliminados = false): Builder
    {
        if ($this->nivel($usuario, $modulo) === AlcanceDatos::Todos) {
            return $query;
        }

        $colaboradores = $conEliminados ? Colaborador::query()->withTrashed() : Colaborador::query();

        return $query->whereIn($columna, $this->limitarColaboradores($colaboradores, $usuario, $modulo)->select('id'));
    }

    /**
     * Para módulos que se acotan por sucursal (vacantes, candidatos): Propios
     * y Equipo equivalen a "sus sucursales". Registros sin sucursal son
     * visibles para todos (no pertenecen a ninguna).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function limitarPorSucursal(Builder $query, User $usuario, string $modulo, string $columna = 'sucursal_id'): Builder
    {
        $nivel = $this->nivel($usuario, $modulo);

        if ($nivel === AlcanceDatos::Todos) {
            return $query;
        }

        $sucursales = $this->sucursalesPara($usuario, $nivel === AlcanceDatos::Region ? AlcanceDatos::Region : AlcanceDatos::Sucursal)->all();

        return $query->where(fn (Builder $q) => $q->whereIn($columna, $sucursales)->orWhereNull($columna));
    }

    public function alcanzaColaborador(User $usuario, ?Colaborador $colaborador, string $modulo): bool
    {
        if ($colaborador === null) {
            return $this->nivel($usuario, $modulo) === AlcanceDatos::Todos;
        }

        if ($usuario->colaborador_id !== null && $usuario->colaborador_id === $colaborador->id) {
            return true;
        }

        return match ($this->nivel($usuario, $modulo)) {
            AlcanceDatos::Todos => true,
            AlcanceDatos::Region, AlcanceDatos::Sucursal => $colaborador->sucursal_principal_id !== null
                && $this->sucursalesPara($usuario, $this->nivel($usuario, $modulo))->contains($colaborador->sucursal_principal_id),
            AlcanceDatos::Equipo => $usuario->colaborador_id !== null
                && ($colaborador->jefe_id === $usuario->colaborador_id || $colaborador->gerente_id === $usuario->colaborador_id),
            AlcanceDatos::Propios => false,
        };
    }

    /**
     * ¿Le llega a $usuario el aviso de una acción por permiso sobre esta
     * persona? Si el permiso pertenece a un módulo con alcance
     * (config permisos_modulos.alcance_avisos, p. ej. aprobar solicitudes →
     * «Solicitudes»), manda el alcance que ese rol tiene en el módulo en
     * Roles y permisos: un gerente con alcance «Sucursal» solo recibe avisos
     * de su sucursal; RH con «Todos», de todas. Sin módulo (o sin sus
     * permisos de alcance creados todavía) se usa el alcance histórico.
     */
    public function alcanzaParaAviso(User $usuario, Colaborador $colaborador, string $permiso): bool
    {
        $modulo = $this->moduloDeAviso($permiso);

        if ($modulo === null || ! $this->existenEnBase($this->alcancesDe($modulo))) {
            return $this->organizacional->alcanzaColaborador($usuario, $colaborador);
        }

        return $this->alcanzaColaborador($usuario, $colaborador, $modulo);
    }

    public function moduloDeAviso(string $permiso): ?string
    {
        // El permiso lleva puntos: se indexa el arreglo, no con notación de punto.
        $modulo = ((array) config('permisos_modulos.alcance_avisos', []))[$permiso] ?? null;

        return is_string($modulo) && $this->alcancesDe($modulo) !== [] ? $modulo : null;
    }

    /**
     * Sucursales propias (principal + adicionales) o, en «región», todas las
     * sucursales de las regiones de la matriz comercial a las que pertenecen
     * (zona ligada a la sucursal → su región). Nunca menos que las propias.
     *
     * @return Collection<int, int>
     */
    public function sucursalesPara(User $usuario, AlcanceDatos $nivel): Collection
    {
        $colaborador = $usuario->colaborador;

        if ($colaborador === null) {
            return collect();
        }

        $propias = collect([$colaborador->sucursal_principal_id])
            ->merge($colaborador->sucursalesAdicionales()->pluck('sucursales.id'))
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($nivel !== AlcanceDatos::Region || $propias->isEmpty()) {
            return $propias;
        }

        $regiones = NodoComercial::query()
            ->where('tipo', TipoNodoComercial::Zona->value)
            ->whereIn('sucursal_id', $propias->all())
            ->whereNotNull('parent_id')
            ->pluck('parent_id');

        if ($regiones->isEmpty()) {
            return $propias;
        }

        return NodoComercial::query()
            ->where('tipo', TipoNodoComercial::Zona->value)
            ->whereIn('parent_id', $regiones->all())
            ->whereNotNull('sucursal_id')
            ->pluck('sucursal_id')
            ->map(fn ($id): int => (int) $id)
            ->merge($propias)
            ->unique()
            ->values();
    }

    /**
     * Módulos con su configuración para la pantalla de roles.
     *
     * @return array<string, array{etiqueta: string, grupo: string, acceso: string, alcances: array<string, string>, acciones: array<string, string>}>
     */
    public function modulos(): array
    {
        $modulos = [];

        foreach ((array) config('permisos_modulos.modulos', []) as $clave => $definicion) {
            if (! is_array($definicion)) {
                continue;
            }

            $modulos[(string) $clave] = [
                'etiqueta' => (string) ($definicion['etiqueta'] ?? $clave),
                'grupo' => (string) ($definicion['grupo'] ?? 'Otros'),
                'acceso' => (string) ($definicion['acceso'] ?? ''),
                'alcances' => array_map('strval', (array) ($definicion['alcances'] ?? [])),
                // 'perm' => 'Etiqueta' o 'perm' => ['etiqueta' => …, 'descripcion' => …].
                'acciones' => array_map(
                    fn ($accion): string => is_array($accion) ? (string) ($accion['etiqueta'] ?? '') : (string) $accion,
                    (array) ($definicion['acciones'] ?? []),
                ),
            ];
        }

        return $modulos;
    }

    /**
     * @return array<string, string>
     */
    private function alcancesDe(string $modulo): array
    {
        return $this->modulos()[$modulo]['alcances'] ?? [];
    }

    /**
     * @param  array<string, string>  $permisos
     */
    private function existenEnBase(array $permisos): bool
    {
        $existentes = $this->registrar->getPermissions()->pluck('name');

        foreach ($permisos as $permiso) {
            if (! $existentes->contains($permiso)) {
                return false;
            }
        }

        return true;
    }

    private function nivelHistorico(User $usuario): AlcanceDatos
    {
        return match (true) {
            $this->organizacional->tieneAlcanceGlobal($usuario) => AlcanceDatos::Todos,
            $this->organizacional->tieneAlcanceDeSucursal($usuario) => AlcanceDatos::Sucursal,
            $usuario->hasRole('jefe_directo') => AlcanceDatos::Equipo,
            default => AlcanceDatos::Propios,
        };
    }
}
