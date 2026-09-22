<?php

namespace App\Services\Colaboradores;

use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Estructura jerárquica real de personas (jefe inmediato, gerente,
 * subordinados) — distinta del organigrama de PUESTOS
 * (App\Services\Administracion\JerarquiaPuestoService) y de la matriz
 * comercial (docs/ORGANIGRAMA.md). Es la fuente que usan las aprobaciones
 * (visto bueno del jefe, captura de evaluaciones) para saber quién puede
 * operar sobre quién.
 */
class JerarquiaColaboradorService
{
    /**
     * Profundidad máxima al recorrer la cadena de jefes: protege contra
     * ciclos accidentales (A jefe de B y B jefe de A) en datos capturados a mano.
     */
    private const PROFUNDIDAD_MAXIMA = 15;

    public function jefeDe(Colaborador $colaborador): ?Colaborador
    {
        return $colaborador->jefe;
    }

    /**
     * Gerente del colaborador: el capturado explícitamente (gerente_id) o,
     * si no hay, el jefe del jefe inmediato.
     */
    public function gerenteDe(Colaborador $colaborador): ?Colaborador
    {
        if ($colaborador->gerente_id !== null) {
            return $colaborador->gerente;
        }

        return $colaborador->jefe?->jefe;
    }

    /**
     * @return EloquentCollection<int, Colaborador>
     */
    public function subordinadosDirectos(Colaborador $colaborador): EloquentCollection
    {
        return Colaborador::query()
            ->where('jefe_id', $colaborador->id)
            ->whereIn('estatus', [EstadoUsuario::Activo->value, EstadoUsuario::EnIncorporacion->value])
            ->with(['puesto:id,nombre', 'departamento:id,nombre', 'sucursalPrincipal:id,nombre'])
            ->orderBy('name')
            ->get();
    }

    /**
     * true si $superior es jefe inmediato o gerente de $colaborador, o está
     * por encima en la cadena de jefes (jefe del jefe...).
     */
    public function esSuperiorDe(Colaborador $superior, Colaborador $colaborador): bool
    {
        if ($colaborador->gerente_id === $superior->id) {
            return true;
        }

        $actual = $colaborador;

        for ($i = 0; $i < self::PROFUNDIDAD_MAXIMA; $i++) {
            if ($actual->jefe_id === null) {
                return false;
            }

            if ($actual->jefe_id === $superior->id) {
                return true;
            }

            $siguiente = Colaborador::query()->select(['id', 'jefe_id'])->where('id', $actual->jefe_id)->first();

            if ($siguiente === null) {
                return false;
            }

            $actual = $siguiente;
        }

        return false;
    }

    /**
     * true si la cuenta pertenece al jefe inmediato (o gerente) del colaborador.
     */
    public function usuarioEsJefeDe(User $usuario, Colaborador $colaborador): bool
    {
        if ($usuario->colaborador_id === null) {
            return false;
        }

        return $colaborador->jefe_id === $usuario->colaborador_id || $colaborador->gerente_id === $usuario->colaborador_id;
    }

    /**
     * Árbol de personas por sucursal/departamento en una sola consulta (sin
     * N+1): cada nodo trae sus subordinados directos. Las raíces son los
     * colaboradores cuyo jefe no está dentro del conjunto filtrado.
     *
     * @param  Collection<int, int>|null  $sucursalesVisibles  null = sin restricción
     * @return list<array<string, mixed>>
     */
    public function organigrama(?int $sucursalId, ?int $departamentoId, ?Collection $sucursalesVisibles = null): array
    {
        $colaboradores = Colaborador::query()
            ->whereIn('estatus', [EstadoUsuario::Activo->value, EstadoUsuario::EnIncorporacion->value])
            ->when($sucursalId !== null, fn ($q) => $q->where('sucursal_principal_id', $sucursalId))
            ->when($departamentoId !== null, fn ($q) => $q->where('departamento_id', $departamentoId))
            ->when($sucursalesVisibles !== null, fn ($q) => $q->whereIn('sucursal_principal_id', $sucursalesVisibles))
            ->with(['puesto:id,nombre,nivel_jerarquico', 'departamento:id,nombre', 'sucursalPrincipal:id,nombre,empresa_id', 'sucursalPrincipal.empresa:id,nombre'])
            ->orderBy('name')
            ->get(['id', 'name', 'apellidos', 'numero_empleado', 'jefe_id', 'gerente_id', 'puesto_id', 'departamento_id', 'sucursal_principal_id']);

        $ids = $colaboradores->pluck('id')->all();
        $porJefe = $colaboradores->groupBy(fn (Colaborador $c) => in_array($c->jefe_id, $ids, true) ? (int) $c->jefe_id : 0);

        $construir = function (int $jefeId, int $nivel) use (&$construir, $porJefe): array {
            if ($nivel > self::PROFUNDIDAD_MAXIMA) {
                return [];
            }

            return $porJefe->get($jefeId, collect())
                ->map(fn (Colaborador $c) => [
                    ...$this->resumen($c),
                    'subordinados' => $construir($c->id, $nivel + 1),
                ])
                ->values()
                ->all();
        };

        return array_values($construir(0, 0));
    }

    /**
     * @return array<string, mixed>
     */
    public function resumen(?Colaborador $colaborador): array
    {
        if ($colaborador === null) {
            return [];
        }

        return [
            'id' => $colaborador->id,
            'nombre' => $colaborador->nombreCompleto(),
            'numero_empleado' => $colaborador->numero_empleado,
            'puesto' => $colaborador->puesto?->nombre,
            'departamento' => $colaborador->departamento?->nombre,
            'sucursal' => $colaborador->sucursalPrincipal?->nombre,
            'empresa' => $colaborador->sucursalPrincipal?->empresa?->nombre,
        ];
    }

    /**
     * Jerarquía completa de un colaborador para API (jefe, gerente,
     * subordinados directos y estructura).
     *
     * @return array<string, mixed>
     */
    public function jerarquia(Colaborador $colaborador): array
    {
        $colaborador->loadMissing(['jefe.puesto', 'gerente.puesto', 'jefe.jefe.puesto', 'puesto', 'departamento', 'sucursalPrincipal.empresa']);
        $gerente = $this->gerenteDe($colaborador);

        return [
            'colaborador' => $this->resumen($colaborador),
            'jefe_inmediato' => $colaborador->jefe !== null ? $this->resumen($colaborador->jefe) : null,
            'gerente' => $gerente !== null ? $this->resumen($gerente) : null,
            'subordinados_directos' => $this->subordinadosDirectos($colaborador)->map(fn (Colaborador $c) => $this->resumen($c))->values()->all(),
        ];
    }
}
