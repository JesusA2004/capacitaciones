<?php

namespace App\Services\Vacantes;

use App\Enums\EstadoCandidato;
use App\Enums\EstadoVacante;
use App\Models\HeadcountTarget;
use App\Models\User;
use App\Models\Vacante;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Headcount\HeadcountService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Listado de VACANTES (docs/HEADCOUNT_Y_VACANTES.md): una fila por cada
 * vacante real — qué puesto falta, en qué sucursal, cuántas plazas, desde
 * cuándo y cuántos candidatos lleva — no la tabla de plantilla por
 * sucursal (eso es Headcount y vive en el detalle de cada sucursal,
 * Administración > Sucursales).
 *
 * Única regla para la web (Rh\VacanteController) y la API móvil
 * (Api\V1\Rh\VacanteController): alcance organizacional, filtros y "activas
 * por defecto" (sin cubiertas ni canceladas) viven solo aquí.
 */
class VacantesListadoService
{
    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly HeadcountService $headcount,
    ) {}

    /**
     * @param  array{busqueda?: string|null, sucursal_id?: int|string|null, puesto_id?: int|string|null, departamento_id?: int|string|null, estado?: string|null}  $filtros
     * @return Builder<Vacante>
     */
    public function consulta(User $usuario, array $filtros = []): Builder
    {
        $estado = EstadoVacante::tryFrom((string) ($filtros['estado'] ?? ''));
        $busqueda = trim((string) ($filtros['busqueda'] ?? ''));

        $consulta = Vacante::query()
            ->with(['sucursal:id,nombre', 'departamento:id,nombre', 'puesto:id,nombre'])
            ->withCount([
                'candidatos',
                'candidatos as candidatos_activos_count' => fn (Builder $q) => $q->whereIn('estado', $this->estadosCandidatoActivos()),
            ])
            ->when(
                $estado !== null,
                fn (Builder $q) => $q->where('estado', $estado?->value),
                fn (Builder $q) => $q->whereNotIn('estado', [EstadoVacante::Cubierta->value, EstadoVacante::Cancelada->value]),
            )
            ->when($filtros['sucursal_id'] ?? null, fn (Builder $q, int|string $id) => $q->where('sucursal_id', (int) $id))
            ->when($filtros['puesto_id'] ?? null, fn (Builder $q, int|string $id) => $q->where('puesto_id', (int) $id))
            ->when($filtros['departamento_id'] ?? null, fn (Builder $q, int|string $id) => $q->where('departamento_id', (int) $id))
            ->when($busqueda !== '', fn (Builder $q) => $q->where(fn (Builder $s) => $s
                ->whereHas('puesto', fn (Builder $p) => $p->where('nombre', 'like', "%{$busqueda}%"))
                ->orWhereHas('sucursal', fn (Builder $p) => $p->where('nombre', 'like', "%{$busqueda}%"))))
            ->orderBy('fecha_apertura')
            ->orderBy('id');

        return $this->alcance->limitarPorSucursal($consulta, $usuario);
    }

    /**
     * Filas listas para la tabla/tarjetas, con la plantilla de ese
     * (sucursal, puesto) como contexto: "faltan 3 de 5".
     *
     * @param  EloquentCollection<int, Vacante>  $vacantes
     * @return list<array<string, mixed>>
     */
    public function filas(EloquentCollection $vacantes): array
    {
        $autorizadas = HeadcountTarget::query()
            ->whereIn('sucursal_id', $vacantes->pluck('sucursal_id')->filter()->unique())
            ->get(['sucursal_id', 'puesto_id', 'plantilla_autorizada'])
            ->keyBy(fn (HeadcountTarget $t) => sprintf('%d:%d', $t->sucursal_id, $t->puesto_id));
        $actuales = $this->headcount->plantillaActualPorSucursalPuesto($vacantes->pluck('sucursal_id')->filter()->unique()->values());

        $filas = [];

        foreach ($vacantes as $vacante) {
            $filas[] = $this->fila($vacante, $autorizadas, $actuales);
        }

        return $filas;
    }

    /**
     * KPIs del estado general dentro del alcance (no dependen de los
     * filtros en pantalla).
     *
     * @return array{vacantes_abiertas: int, plazas_disponibles: int, vacantes_automaticas: int, vacantes_manuales: int, en_reclutamiento: int, canceladas: int, candidatos_activos: int, dias_promedio_abierta: int, costo_mensual: float}
     */
    public function kpis(User $usuario): array
    {
        $todas = $this->alcance->limitarPorSucursal(
            Vacante::query()->withCount(['candidatos as candidatos_activos_count' => fn (Builder $q) => $q->whereIn('estado', $this->estadosCandidatoActivos())]),
            $usuario,
        )->get();

        $activas = $todas->reject(fn (Vacante $v) => in_array($v->estado, [EstadoVacante::Cubierta, EstadoVacante::Cancelada], true));

        return [
            'vacantes_abiertas' => $activas->count(),
            'plazas_disponibles' => (int) $activas->sum('plazas_disponibles'),
            'vacantes_automaticas' => $activas->where('generada_automaticamente', true)->count(),
            'vacantes_manuales' => $activas->where('generada_automaticamente', false)->count(),
            'en_reclutamiento' => $activas->filter(fn (Vacante $v) => $v->estado !== EstadoVacante::Abierta)->count(),
            'canceladas' => $todas->filter(fn (Vacante $v) => $v->estado === EstadoVacante::Cancelada)->count(),
            'candidatos_activos' => (int) $activas->sum('candidatos_activos_count'),
            'dias_promedio_abierta' => $activas->isEmpty() ? 0 : (int) round($activas->avg(fn (Vacante $v) => $v->diasAbierta())),
            'costo_mensual' => (float) $activas->sum(fn (Vacante $v) => (float) ($v->sueldo_mensual ?? 0)),
        ];
    }

    /**
     * @param  Collection<string, HeadcountTarget>  $autorizadas
     * @param  Collection<non-falsy-string, int>  $actuales
     * @return array<string, mixed>
     */
    private function fila(Vacante $vacante, Collection $autorizadas, Collection $actuales): array
    {
        $clave = sprintf('%d:%d', (int) $vacante->sucursal_id, (int) $vacante->puesto_id);
        $autorizada = $autorizadas->get($clave)?->plantilla_autorizada;
        $actual = (int) ($actuales[$clave] ?? 0);

        return [
            'id' => $vacante->id,
            'puesto_id' => $vacante->puesto_id,
            'puesto' => $vacante->puesto?->nombre,
            'sucursal_id' => $vacante->sucursal_id,
            'sucursal' => $vacante->sucursal?->nombre,
            'departamento' => $vacante->departamento?->nombre,
            'estado' => $vacante->estado->value,
            'estado_etiqueta' => $vacante->estado->etiqueta(),
            'motivo' => $vacante->motivo->etiqueta(),
            'generada_automaticamente' => $vacante->generada_automaticamente,
            'fecha_apertura' => $vacante->fecha_apertura->toDateString(),
            'dias_abierta' => $vacante->diasAbierta(),
            'plazas_requeridas' => $vacante->plazas_requeridas,
            'plazas_disponibles' => $vacante->plazas_disponibles,
            'candidatos_activos' => (int) $vacante->getAttribute('candidatos_activos_count'),
            'candidatos_total' => (int) $vacante->getAttribute('candidatos_count'),
            'sueldo_mensual' => $vacante->sueldo_mensual !== null ? (float) $vacante->sueldo_mensual : null,
            'plantilla_autorizada' => $autorizada !== null ? (int) $autorizada : null,
            'plantilla_actual' => $actual,
            'faltantes_reales' => $autorizada !== null ? max((int) $autorizada - $actual, 0) : null,
        ];
    }

    /**
     * @return list<string>
     */
    private function estadosCandidatoActivos(): array
    {
        return array_values(array_map(
            fn (EstadoCandidato $e) => $e->value,
            array_filter(EstadoCandidato::cases(), fn (EstadoCandidato $e) => ! $e->esTerminal()),
        ));
    }
}
