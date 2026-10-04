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
     * Totales concretos de lo que se está viendo (mismas filas del listado):
     * cuántas plazas faltan de cada puesto —gerentes, gestores, etc.— y en
     * qué sucursales. Sin costos: el costo vive en Campañas.
     *
     * @param  list<array<string, mixed>>  $filas
     * @return array{vacantes: int, plazas: int, sucursales: int, por_puesto: list<array{puesto_id: int|null, puesto: string, plazas: int, sucursales: list<array{sucursal_id: int|null, sucursal: string, plazas: int}>}>}
     */
    public function resumen(array $filas): array
    {
        $porPuesto = [];
        $sucursales = [];

        foreach ($filas as $fila) {
            $puestoId = is_int($fila['puesto_id'] ?? null) ? $fila['puesto_id'] : null;
            $sucursalId = is_int($fila['sucursal_id'] ?? null) ? $fila['sucursal_id'] : null;
            $plazas = is_int($fila['plazas_disponibles'] ?? null) ? $fila['plazas_disponibles'] : 0;
            $clavePuesto = sprintf('p%d', $puestoId ?? 0);
            $claveSucursal = sprintf('s%d', $sucursalId ?? 0);

            $porPuesto[$clavePuesto] ??= [
                'puesto_id' => $puestoId,
                'puesto' => is_string($fila['puesto'] ?? null) ? $fila['puesto'] : 'Puesto sin definir',
                'plazas' => 0,
                'sucursales' => [],
            ];
            $porPuesto[$clavePuesto]['plazas'] += $plazas;
            $porPuesto[$clavePuesto]['sucursales'][$claveSucursal] ??= [
                'sucursal_id' => $sucursalId,
                'sucursal' => is_string($fila['sucursal'] ?? null) ? $fila['sucursal'] : 'Sin sucursal',
                'plazas' => 0,
            ];
            $porPuesto[$clavePuesto]['sucursales'][$claveSucursal]['plazas'] += $plazas;
            $sucursales[$claveSucursal] = true;
        }

        $lista = [];

        foreach ($porPuesto as $puesto) {
            $detalle = array_values($puesto['sucursales']);
            usort($detalle, fn (array $a, array $b): int => [$b['plazas'], $a['sucursal']] <=> [$a['plazas'], $b['sucursal']]);
            $lista[] = [...$puesto, 'sucursales' => $detalle];
        }

        usort($lista, fn (array $a, array $b): int => [$b['plazas'], $a['puesto']] <=> [$a['plazas'], $b['puesto']]);

        return [
            'vacantes' => count($filas),
            'plazas' => array_sum(array_column($lista, 'plazas')),
            'sucursales' => count($sucursales),
            'por_puesto' => $lista,
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
