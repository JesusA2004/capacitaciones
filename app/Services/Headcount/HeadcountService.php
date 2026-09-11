<?php

namespace App\Services\Headcount;

use App\Enums\EstadoUsuario;
use App\Models\HeadcountTarget;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Plantilla autorizada (HeadcountTarget, importada/editada a mano) vs
 * plantilla actual (SIEMPRE calculada en vivo contando colaboradores
 * activos — nunca importada ni capturada). Ver docs/HEADCOUNT_Y_VACANTES.md.
 *
 * "Vacantes" en este servicio es la cifra derivada (autorizada - actual,
 * nunca negativa), no las filas de la tabla `vacantes` — esas se
 * sincronizan a partir de esta cifra en VacanteAutoGenerationService.
 */
class HeadcountService
{
    /**
     * Plantilla actual por (sucursal_id, puesto_id): colaboradores activos
     * agrupados. Única fuente de "actual" en todo el módulo.
     *
     * @param  Collection<int, int>|null  $sucursalesIds  null = todas (ya acotadas por el caller vía alcance)
     * @return Collection<non-falsy-string, int> clave "sucursal_id:puesto_id"
     */
    public function plantillaActualPorSucursalPuesto(?Collection $sucursalesIds = null): Collection
    {
        return User::query()
            ->where('estatus', EstadoUsuario::Activo->value)
            ->whereNotNull('puesto_id')
            ->whereNotNull('sucursal_principal_id')
            ->when($sucursalesIds !== null, fn ($q) => $q->whereIn('sucursal_principal_id', $sucursalesIds))
            ->selectRaw('sucursal_principal_id, puesto_id, count(*) as total')
            ->groupBy('sucursal_principal_id', 'puesto_id')
            ->get()
            ->mapWithKeys(fn ($fila) => [sprintf('%d:%d', $fila->sucursal_principal_id, $fila->puesto_id) => (int) $fila->getAttribute('total')]);
    }

    /**
     * Igual que arriba pero acumulado solo por sucursal (para KPIs de
     * "plantilla actual" que no distinguen puesto).
     *
     * @param  Collection<int, int>|null  $sucursalesIds
     * @return Collection<int, int> clave sucursal_id
     */
    public function plantillaActualPorSucursal(?Collection $sucursalesIds = null): Collection
    {
        return User::query()
            ->where('estatus', EstadoUsuario::Activo->value)
            ->whereNotNull('sucursal_principal_id')
            ->when($sucursalesIds !== null, fn ($q) => $q->whereIn('sucursal_principal_id', $sucursalesIds))
            ->selectRaw('sucursal_principal_id, count(*) as total')
            ->groupBy('sucursal_principal_id')
            ->pluck('total', 'sucursal_principal_id')
            ->map(fn ($v) => (int) $v);
    }

    /**
     * Resumen por sucursal: autorizada (suma de HeadcountTarget), actual
     * (colaboradores activos), vacantes (derivada), cumplimiento %.
     *
     * @param  Collection<int, int>|null  $sucursalesIds  null = todas
     * @return Collection<int, array{sucursal_id: int, sucursal: string, plantilla_autorizada: int, plantilla_actual: int, vacantes: int, cumplimiento: float}>
     */
    public function resumenPorSucursal(?Collection $sucursalesIds = null): Collection
    {
        $autorizadaPorSucursal = HeadcountTarget::query()
            ->when($sucursalesIds !== null, fn ($q) => $q->whereIn('sucursal_id', $sucursalesIds))
            ->selectRaw('sucursal_id, sum(plantilla_autorizada) as total')
            ->groupBy('sucursal_id')
            ->with('sucursal:id,nombre')
            ->get()
            ->keyBy('sucursal_id');

        $actualPorSucursal = $this->plantillaActualPorSucursal($sucursalesIds);

        $sucursalesIdsUnion = $autorizadaPorSucursal->keys()
            ->merge($actualPorSucursal->keys())
            ->unique()
            ->values();

        $nombresSucursal = Sucursal::query()
            ->whereIn('id', $sucursalesIdsUnion)
            ->pluck('nombre', 'id');

        return $sucursalesIdsUnion->map(function (int|string $sucursalId) use ($autorizadaPorSucursal, $actualPorSucursal, $nombresSucursal) {
            $sucursalId = (int) $sucursalId;
            $autorizada = (int) ($autorizadaPorSucursal[$sucursalId]->total ?? 0);
            $actual = (int) ($actualPorSucursal[$sucursalId] ?? 0);

            return [
                'sucursal_id' => $sucursalId,
                'sucursal' => (string) ($nombresSucursal[$sucursalId] ?? '—'),
                'plantilla_autorizada' => $autorizada,
                'plantilla_actual' => $actual,
                'vacantes' => max($autorizada - $actual, 0),
                'cumplimiento' => $autorizada > 0 ? round(($actual / $autorizada) * 100, 1) : 0.0,
            ];
        })->sortBy('sucursal')->values();
    }

    /**
     * Drilldown por puesto dentro de una sucursal: autorizado, actual,
     * faltante, excedente.
     *
     * @return Collection<int, array{puesto_id: int, puesto: string, plantilla_autorizada: int, plantilla_actual: int, faltante: int, excedente: int}>
     */
    public function resumenPorPuesto(int $sucursalId): Collection
    {
        $targets = HeadcountTarget::query()
            ->where('sucursal_id', $sucursalId)
            ->with('puesto:id,nombre')
            ->get()
            ->keyBy('puesto_id');

        $actualPorPuesto = User::query()
            ->where('estatus', EstadoUsuario::Activo->value)
            ->where('sucursal_principal_id', $sucursalId)
            ->whereNotNull('puesto_id')
            ->selectRaw('puesto_id, count(*) as total')
            ->groupBy('puesto_id')
            ->pluck('total', 'puesto_id')
            ->map(fn ($v) => (int) $v);

        $puestoIdsUnion = $targets->keys()->merge($actualPorPuesto->keys())->unique()->values();

        $nombresPuesto = Puesto::query()->whereIn('id', $puestoIdsUnion)->pluck('nombre', 'id');

        return $puestoIdsUnion->map(function (int $puestoId) use ($targets, $actualPorPuesto, $nombresPuesto) {
            $autorizada = (int) ($targets[$puestoId]->plantilla_autorizada ?? 0);
            $actual = (int) ($actualPorPuesto[$puestoId] ?? 0);

            return [
                'puesto_id' => $puestoId,
                'puesto' => (string) ($nombresPuesto[$puestoId] ?? '—'),
                'plantilla_autorizada' => $autorizada,
                'plantilla_actual' => $actual,
                'faltante' => max($autorizada - $actual, 0),
                'excedente' => max($actual - $autorizada, 0),
            ];
        })->sortBy('puesto')->values();
    }

    /**
     * @param  Collection<int, int>|null  $sucursalesIds
     * @return array{plantilla_autorizada: int, plantilla_actual: int, vacantes: int, cumplimiento: float, sucursales_bajo_cobertura: int}
     */
    public function totalesGenerales(?Collection $sucursalesIds = null): array
    {
        $porSucursal = $this->resumenPorSucursal($sucursalesIds);

        $autorizada = (int) $porSucursal->sum('plantilla_autorizada');
        $actual = (int) $porSucursal->sum('plantilla_actual');

        return [
            'plantilla_autorizada' => $autorizada,
            'plantilla_actual' => $actual,
            'vacantes' => max($autorizada - $actual, 0),
            'cumplimiento' => $autorizada > 0 ? round(($actual / $autorizada) * 100, 1) : 0.0,
            'sucursales_bajo_cobertura' => $porSucursal->filter(fn (array $fila) => $fila['vacantes'] > 0)->count(),
        ];
    }

    /**
     * Vacante derivada puntual para una (sucursal, puesto): cuántas plazas
     * autorizadas siguen sin cubrir ahora mismo. Usado por
     * VacanteAutoGenerationService para decidir si abrir/cerrar una vacante
     * automática.
     */
    public function vacantesDerivadas(int $sucursalId, int $puestoId): int
    {
        $autorizada = (int) HeadcountTarget::query()
            ->where('sucursal_id', $sucursalId)
            ->where('puesto_id', $puestoId)
            ->value('plantilla_autorizada');

        $actual = (int) User::query()
            ->where('estatus', EstadoUsuario::Activo->value)
            ->where('sucursal_principal_id', $sucursalId)
            ->where('puesto_id', $puestoId)
            ->count();

        return max($autorizada - $actual, 0);
    }

    /**
     * Todos los pares (sucursal_id, puesto_id) con un HeadcountTarget
     * vigente — universo que VacanteAutoGenerationService::sincronizarTodo()
     * recorre.
     *
     * @return Collection<int, array{sucursal_id: int, puesto_id: int}>
     */
    public function paresConTarget(): Collection
    {
        return HeadcountTarget::query()
            ->select('sucursal_id', 'puesto_id')
            ->get()
            ->map(fn (HeadcountTarget $t) => ['sucursal_id' => $t->sucursal_id, 'puesto_id' => $t->puesto_id]);
    }
}
