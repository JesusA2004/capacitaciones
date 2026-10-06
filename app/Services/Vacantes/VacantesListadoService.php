<?php

namespace App\Services\Vacantes;

use App\Enums\EstadoCandidato;
use App\Enums\EstadoVacante;
use App\Models\User;
use App\Models\Vacante;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Headcount\HeadcountService;
use App\Services\Headcount\PuestosPlantillaService;
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
        private readonly PuestosPlantillaService $puestos,
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
            // Una automática abierta cuya plaza ya está ocupada (fila vieja que
            // no se cerró) no es una vacante real: nunca se lista como activa.
            ->when(
                $estado === null || in_array($estado->value, EstadoVacante::valoresAbiertos(), true),
                fn (Builder $q) => $q->whereNotIn('id', $this->automaticasSinFaltante()),
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
        $sucursales = $vacantes->pluck('sucursal_id')->filter()->unique()->values();
        $autorizadas = $this->headcount->plantillaAutorizadaPorSucursalPuesto($sucursales);
        $actuales = $this->headcount->plantillaActualPorSucursalPuesto($sucursales);

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
     * Plazas reales de cada vacante: en una AUTOMÁTICA salen siempre del
     * cálculo en vivo (autorizada − ocupada), nunca de la columna guardada,
     * para que «plazas por cubrir» y «N de M ocupadas» no se contradigan.
     *
     * @param  EloquentCollection<int, Vacante>  $vacantes
     * @return array<int, int> vacante_id => plazas
     */
    public function plazasReales(EloquentCollection $vacantes): array
    {
        return collect($this->filas($vacantes))->mapWithKeys(fn (array $f) => [(int) $f['id'] => (int) $f['plazas_disponibles']])->all();
    }

    /**
     * Única fuente para decidir si una vacante todavía puede recibir un
     * candidato (CLAUDE.md §2): cubierta/cancelada nunca, y una automática
     * abierta solo si de verdad le falta plaza (autorizada − ocupada > 0),
     * nunca por la columna guardada. Úsala SIEMPRE antes de ligar un
     * candidato a una vacante (alta, edición, campañas) — bajo
     * `lockForUpdate()` para que sea race-safe.
     */
    public function tieneCupo(Vacante $vacante): bool
    {
        if (in_array($vacante->estado, [EstadoVacante::Cubierta, EstadoVacante::Cancelada], true)) {
            return false;
        }

        if (! $vacante->generada_automaticamente) {
            return $vacante->plazas_disponibles > 0;
        }

        $plazas = $this->plazasReales(new EloquentCollection([$vacante]));

        return ($plazas[$vacante->id] ?? 0) > 0;
    }

    /**
     * IDs de vacantes automáticas abiertas cuya plaza ya no falta
     * (autorizada − ocupada = 0). `people:sincronizar-vacantes` las cierra;
     * mientras tanto el listado no las muestra como vacantes reales.
     *
     * @return list<int>
     */
    private function automaticasSinFaltante(): array
    {
        $abiertas = Vacante::query()
            ->where('generada_automaticamente', true)
            ->whereIn('estado', EstadoVacante::valoresAbiertos())
            ->get(['id', 'sucursal_id', 'puesto_id']);

        if ($abiertas->isEmpty()) {
            return [];
        }

        $sucursales = $abiertas->pluck('sucursal_id')->filter()->unique()->values();
        $autorizadas = $this->headcount->plantillaAutorizadaPorSucursalPuesto($sucursales);
        $actuales = $this->headcount->plantillaActualPorSucursalPuesto($sucursales);

        return array_values($abiertas
            ->filter(function (Vacante $v) use ($autorizadas, $actuales): bool {
                $clave = $this->clave($v);

                return max((int) ($autorizadas[$clave] ?? 0) - (int) ($actuales[$clave] ?? 0), 0) === 0;
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all());
    }

    private function clave(Vacante $vacante): string
    {
        return sprintf('%d:%d', (int) $vacante->sucursal_id, $this->puestos->canonico((int) $vacante->puesto_id));
    }

    /**
     * @param  Collection<string, int>  $autorizadas
     * @param  Collection<non-falsy-string, int>  $actuales
     * @return array<string, mixed>
     */
    private function fila(Vacante $vacante, Collection $autorizadas, Collection $actuales): array
    {
        $clave = $this->clave($vacante);
        $autorizada = $autorizadas->get($clave);
        $actual = (int) ($actuales[$clave] ?? 0);
        $faltantes = $autorizada !== null ? max($autorizada - $actual, 0) : null;
        $abierta = in_array($vacante->estado->value, EstadoVacante::valoresAbiertos(), true);
        // Automática abierta: sus plazas SON el faltante real. Manual: lo que RH capturó.
        $plazas = $vacante->generada_automaticamente && $abierta ? (int) ($faltantes ?? 0) : $vacante->plazas_disponibles;

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
            'plazas_requeridas' => $vacante->generada_automaticamente && $abierta ? $plazas : $vacante->plazas_requeridas,
            'plazas_disponibles' => $plazas,
            'candidatos_activos' => (int) $vacante->getAttribute('candidatos_activos_count'),
            'candidatos_total' => (int) $vacante->getAttribute('candidatos_count'),
            'plantilla_autorizada' => $autorizada,
            'plantilla_actual' => $actual,
            'faltantes_reales' => $faltantes,
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
