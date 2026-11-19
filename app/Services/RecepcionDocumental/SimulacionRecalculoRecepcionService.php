<?php

namespace App\Services\RecepcionDocumental;

use App\Enums\EstadoCorteRecepcion;
use App\Enums\EstadoRecepcionDocumento;
use App\Models\RecepcionDocumentalCatalogo;
use App\Models\RecepcionDocumentalCorte;
use App\Models\RecepcionDocumentalRenglon;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;

/**
 * Simula, SIN guardar, el recálculo de «No. de papeles» que provocaría un
 * cambio del catálogo de «lo que recibo» en cada corte NO cerrado (y en el
 * mes en curso aunque su corte aún no exista). Por corte y por sucursal:
 *
 *  - antes: lo que hoy suma el corte (esperados = sin «No aplica»);
 *  - sin_cambio: lo que sumaría si se recalcula con el catálogo guardado
 *    (la diferencia con «antes» son eventos nuevos desde el último cálculo);
 *  - despues: lo que sumará con el cambio aplicado;
 *  - conflictos: lo ya recibido/observado que el cambio deja sin esperar o
 *    con otra cantidad (se conserva y se reporta, nunca en silencio).
 *
 * Los cortes cerrados nunca entran: un mes cerrado conserva su foto. Usa
 * exactamente el mismo plan que la materialización real
 * (MaterializadorCorteService::planificar), así lo que se ve es lo que pasa.
 *
 * @phpstan-import-type Plan from MaterializadorCorteService
 * @phpstan-import-type Conflicto from MaterializadorCorteService
 */
class SimulacionRecalculoRecepcionService
{
    public function __construct(
        private readonly CatalogoReglasRecepcion $catalogo,
        private readonly MaterializadorCorteService $materializador,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    /**
     * Periodos que se recalculan al cambiar el catálogo: todo corte no
     * cerrado que ya empezó, más el mes en curso (si su corte no está cerrado).
     *
     * @return list<array{periodo: PeriodoRecepcion, corte: RecepcionDocumentalCorte|null}>
     */
    public function periodosAbiertos(): array
    {
        $actual = PeriodoRecepcion::actual();
        $resultado = [];
        $tieneActual = false;

        $cortes = RecepcionDocumentalCorte::query()->orderBy('anio')->orderBy('mes')->get();

        foreach ($cortes as $corte) {
            $periodo = PeriodoRecepcion::delCorte($corte);

            if ($periodo->clave() === $actual->clave()) {
                $tieneActual = true;
            }

            if ($corte->estaCerrado() || $periodo->esFuturo()) {
                continue;
            }

            $resultado[] = ['periodo' => $periodo, 'corte' => $corte];
        }

        if (! $tieneActual) {
            $resultado[] = ['periodo' => $actual, 'corte' => null];
        }

        return $resultado;
    }

    /**
     * @param  list<RecepcionDocumentalCatalogo>  $borrador  El catálogo completo con el cambio aplicado (sin guardar).
     * @return array{cortes: list<array<string, mixed>>, total: array{antes: int, sin_cambio: int, despues: int, diferencia: int}, conflictos: int, cerrados_intactos: int}
     */
    public function simular(array $borrador, ?User $usuario): array
    {
        $guardadas = $this->catalogo->entradas();
        $conCambio = $this->catalogo->configuradas($borrador);
        $visibles = $usuario !== null ? $this->sucursalesVisibles($usuario) : null;

        $cortes = [];
        $total = ['antes' => 0, 'sin_cambio' => 0, 'despues' => 0, 'diferencia' => 0];
        $conflictos = 0;

        foreach ($this->periodosAbiertos() as ['periodo' => $periodo, 'corte' => $corte]) {
            $planGuardado = $this->materializador->planificar($corte, $this->materializador->calcular($periodo, $guardadas), $periodo);
            $planNuevo = $this->materializador->planificar($corte, $this->materializador->calcular($periodo, $conCambio), $periodo);

            $antes = $this->sumarActual($planNuevo, $visibles);
            $sinCambio = $this->sumarPlan($planGuardado, $visibles);
            $despues = $this->sumarPlan($planNuevo, $visibles);

            $previos = [];

            foreach ($planGuardado['conflictos'] as $c) {
                $previos[sprintf('%d|%s', $c['renglon_id'], $c['tipo'])] = true;
            }

            $nuevosConflictos = array_values(array_filter(
                $planNuevo['conflictos'],
                fn (array $c): bool => ! isset($previos[sprintf('%d|%s', $c['renglon_id'], $c['tipo'])]) && $this->esVisible($c['renglon_id'], $planNuevo, $visibles),
            ));

            $sucursales = [];

            foreach (array_unique([...array_keys($antes['por']), ...array_keys($despues['por']), ...array_keys($sinCambio['por'])]) as $llave) {
                $fila = [
                    'sucursal_id' => $antes['por'][$llave]['id'] ?? $despues['por'][$llave]['id'] ?? $sinCambio['por'][$llave]['id'] ?? null,
                    'nombre' => $antes['por'][$llave]['nombre'] ?? $despues['por'][$llave]['nombre'] ?? $sinCambio['por'][$llave]['nombre'] ?? 'Sin sucursal',
                    'antes' => $antes['por'][$llave]['total'] ?? 0,
                    'sin_cambio' => $sinCambio['por'][$llave]['total'] ?? 0,
                    'despues' => $despues['por'][$llave]['total'] ?? 0,
                ];
                $fila['diferencia'] = $fila['despues'] - $fila['antes'];

                if ($fila['antes'] !== 0 || $fila['despues'] !== 0) {
                    $sucursales[] = $fila;
                }
            }

            usort($sucursales, fn (array $a, array $b): int => [abs($b['despues'] - $b['sin_cambio']) > 0, $a['nombre']] <=> [abs($a['despues'] - $a['sin_cambio']) > 0, $b['nombre']]);

            $cortes[] = [
                'corte_id' => $corte?->id,
                'periodo' => $periodo->clave(),
                'etiqueta' => ucfirst($periodo->etiqueta()),
                'estado' => $corte !== null ? $corte->estado->etiqueta() : 'Se creará al aplicar',
                'antes' => $antes['total'],
                'sin_cambio' => $sinCambio['total'],
                'despues' => $despues['total'],
                'diferencia' => $despues['total'] - $antes['total'],
                'efecto_del_cambio' => $despues['total'] - $sinCambio['total'],
                'sucursales' => $sucursales,
                'conteo' => $planNuevo['conteo'],
                'conflictos' => $nuevosConflictos,
            ];

            $total['antes'] += $antes['total'];
            $total['sin_cambio'] += $sinCambio['total'];
            $total['despues'] += $despues['total'];
            $conflictos += count($nuevosConflictos);
        }

        $total['diferencia'] = $total['despues'] - $total['antes'];

        return [
            'cortes' => $cortes,
            'total' => $total,
            'conflictos' => $conflictos,
            'cerrados_intactos' => RecepcionDocumentalCorte::query()->where('estado', EstadoCorteRecepcion::Cerrado->value)->count(),
        ];
    }

    /**
     * Lo que hoy suma el corte (antes de recalcular).
     *
     * @param  Plan  $plan
     * @param  array<int, true>|null  $visibles
     * @return array{total: int, por: array<string, array{id: int|null, nombre: string, total: int}>}
     */
    private function sumarActual(array $plan, ?array $visibles): array
    {
        $suma = ['total' => 0, 'por' => []];

        foreach ($plan['renglones'] as $r) {
            if ($r->estado !== EstadoRecepcionDocumento::NoAplica) {
                $this->acumular($suma, $r->sucursal_id, $r->sucursal_nombre, $r->cantidad_esperada, $visibles);
            }
        }

        return $suma;
    }

    /**
     * Lo que sumará el corte con el plan aplicado.
     *
     * @param  Plan  $plan
     * @param  array<int, true>|null  $visibles
     * @return array{total: int, por: array<string, array{id: int|null, nombre: string, total: int}>}
     */
    private function sumarPlan(array $plan, ?array $visibles): array
    {
        $suma = ['total' => 0, 'por' => []];

        foreach ($plan['renglones'] as $r) {
            $cambios = $plan['cambios'][$r->id] ?? [];
            $estado = $cambios['estado'] ?? $r->estado;
            $cantidad = isset($cambios['cantidad_esperada']) ? (int) $cambios['cantidad_esperada'] : $r->cantidad_esperada;

            if ($estado !== EstadoRecepcionDocumento::NoAplica) {
                $this->acumular($suma, $r->sucursal_id, $r->sucursal_nombre, $cantidad, $visibles);
            }
        }

        foreach ($plan['nuevos'] as $e) {
            $this->acumular($suma, $e->sucursalId, $e->sucursalNombre, max(1, $e->cantidad), $visibles);
        }

        return $suma;
    }

    /**
     * @param  array{total: int, por: array<string, array{id: int|null, nombre: string, total: int}>}  $suma
     * @param  array<int, true>|null  $visibles
     */
    private function acumular(array &$suma, ?int $sucursalId, ?string $nombre, int $cantidad, ?array $visibles): void
    {
        // Lo que no tiene sucursal lo ve todo el que entra al módulo
        // (mismo criterio que AlcanceOrganizacionalService::limitarPorSucursal).
        if ($sucursalId !== null && $visibles !== null && ! isset($visibles[$sucursalId])) {
            return;
        }

        $llave = $sucursalId !== null ? sprintf('s%d', $sucursalId) : 'sin';
        $suma['por'][$llave] ??= ['id' => $sucursalId, 'nombre' => $nombre ?? 'Sin sucursal', 'total' => 0];
        $suma['por'][$llave]['total'] += $cantidad;
        $suma['total'] += $cantidad;
    }

    /**
     * @param  Plan  $plan
     * @param  array<int, true>|null  $visibles
     */
    private function esVisible(int $renglonId, array $plan, ?array $visibles): bool
    {
        $renglon = $plan['renglones']->get($renglonId);

        return ! $renglon instanceof RecepcionDocumentalRenglon || $renglon->sucursal_id === null || $visibles === null || isset($visibles[$renglon->sucursal_id]);
    }

    /**
     * Sucursales del alcance del usuario en el módulo (null = todas).
     *
     * @return array<int, true>|null
     */
    private function sucursalesVisibles(User $usuario): ?array
    {
        $visibles = $this->alcance->limitarPorSucursal(Sucursal::query(), $usuario, 'sucursales.id', RecepcionDocumentalService::MODULO)->pluck('sucursales.id');

        if ($visibles->count() === Sucursal::query()->count()) {
            return null;
        }

        return array_fill_keys($visibles->map(fn (mixed $id): int => (int) $id)->all(), true);
    }
}
