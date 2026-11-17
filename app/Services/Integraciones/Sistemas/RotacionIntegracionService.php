<?php

namespace App\Services\Integraciones\Sistemas;

use App\Enums\ClasificacionSalida;
use App\Models\Colaborador;
use App\Services\Bajas\PlantillaMensualService;
use App\Services\Bajas\TableroRotacionService;
use App\Services\Rotacion\FiltrosRotacion;
use App\Services\Rotacion\FormulaRotacion;
use App\Services\Rotacion\RotacionMotorService;
use App\Support\FechaMexico;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Rotación por periodo y sucursal para otros sistemas (Reportes calcula hoy
 * la rotación por «los que dejaron de recibir pago»; con esto toma los datos
 * duros de People). No hay reglas nuevas, todo se reutiliza:
 *
 *  - Plantilla en una fecha: PlantillaMensualService::activosEn (ingresó en o
 *    antes de esa fecha y no tiene baja o su baja es en o después), sobre los
 *    colaboradores con sucursal principal y fecha de ingreso.
 *  - Bajas: eventos de RotacionMotorService (vía MovimientosIntegracionService).
 *  - Altas: fecha de ingreso dentro del periodo.
 *  - Índice: FormulaRotacion (bajas ÷ ((inicial + final) ÷ 2) × 100; sin
 *    plantilla = null, nunca 0).
 *
 * Con `mes_calendario` las bajas y la plantilla promedio son las OFICIALES del
 * Tablero de Rotación (RotacionMotorService::celdas, que suma los totales del
 * Excel histórico, y TableroRotacionService::plantillaMes, que usa la foto
 * mensual): el índice coincide con el que ve RH en People.
 */
class RotacionIntegracionService
{
    public function __construct(
        private readonly PlantillaMensualService $plantillas,
        private readonly MovimientosIntegracionService $movimientos,
        private readonly CatalogosIntegracionService $catalogos,
        private readonly RotacionMotorService $motor,
        private readonly TableroRotacionService $tablero,
    ) {}

    /**
     * @param  Collection<int, int>  $sucursales
     * @return array{agrupacion: string, desde: string, hasta: string, fuente_plantilla: string, periodos: list<array<string, mixed>>}
     *
     * @throws InvalidArgumentException si la agrupación no cuadra con el rango
     */
    public function calcular(Collection $sucursales, string $agrupacion, CarbonImmutable $desde, CarbonImmutable $hasta, bool $porSucursal, int $maximoPeriodos): array
    {
        $hoy = FechaMexico::inicioDeHoyInmutable();
        $oficial = $agrupacion === PeriodosIntegracion::MES_CALENDARIO;
        $periodos = array_values(array_filter(
            PeriodosIntegracion::generar($agrupacion, $desde, $hasta, $maximoPeriodos),
            fn (array $p): bool => $p['inicio']->lte($hoy),
        ));

        $personas = $sucursales->isEmpty() ? collect() : Colaborador::query()->withTrashed()
            ->whereIn('sucursal_principal_id', $sucursales->all())
            ->whereNotNull('fecha_ingreso')
            ->get(['id', 'sucursal_principal_id', 'fecha_ingreso', 'fecha_baja', 'estatus']);
        $eventos = $this->movimientos->eventos($sucursales, $desde, $hasta);
        $referencias = $this->catalogos->referenciasSucursales($sucursales);
        $salida = [];

        foreach ($periodos as $periodo) {
            $inicio = $periodo['inicio'];
            $fin = $periodo['fin']->min($hoy);
            $alInicio = $this->plantillas->activosEn($personas, $inicio);
            $alCierre = $this->plantillas->activosEn($personas, $fin);
            $altas = $personas
                ->filter(fn (Colaborador $c): bool => $c->fecha_ingreso !== null && $c->fecha_ingreso->gte($inicio) && $c->fecha_ingreso->lte($fin))
                ->countBy(fn (Colaborador $c): int => (int) $c->sucursal_principal_id);
            $bajas = $oficial ? $this->bajasOficiales($inicio, $fin, $sucursales) : $this->bajasPorSucursal($eventos, $inicio, $fin);

            $filas = [];
            $ids = collect($alInicio->keys())->merge($alCierre->keys())->merge($altas->keys())->merge(array_keys($bajas))
                ->map(fn ($id): int => (int) $id)->unique()->sort()->values();

            foreach ($ids as $id) {
                $promedio = $oficial ? $this->tablero->plantillaMes($inicio, collect([$id])) : null;
                $filas[] = $this->fila((int) ($alInicio[$id] ?? 0), (int) ($altas[$id] ?? 0), $bajas[$id] ?? null, (int) ($alCierre[$id] ?? 0), $promedio, $oficial)
                    + ['sucursal' => $referencias[$id] ?? ['id' => $id, 'clave' => null, 'nombre' => '']];
            }

            $totalBajas = $this->sumarBajas(array_values($bajas));
            $promedioTotal = $oficial ? $this->tablero->plantillaMes($inicio, $sucursales) : null;
            $total = $this->fila((int) $alInicio->sum(), (int) $altas->sum(), $totalBajas, (int) $alCierre->sum(), $promedioTotal, $oficial);

            $salida[] = [
                'clave' => $periodo['clave'],
                'etiqueta' => $periodo['etiqueta'],
                'inicio' => $inicio->toDateString(),
                'fin' => $periodo['fin']->toDateString(),
                'corte' => $fin->toDateString(),
                'completo' => $periodo['completo'],
                'corte_parcial' => $fin->lt($periodo['fin']),
                'totales' => $total,
                'sucursales' => $porSucursal ? array_map(fn (array $f): array => ['sucursal' => $f['sucursal']] + array_diff_key($f, ['sucursal' => true]), $filas) : null,
            ];
        }

        return [
            'agrupacion' => $agrupacion,
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'fuente_plantilla' => $oficial ? 'oficial_mensual' : 'reconstruida_por_fechas',
            'periodos' => $salida,
        ];
    }

    /**
     * @param  array{total: int, voluntarias: int, involuntarias: int, sin_clasificar: int}|null  $bajas
     * @return array<string, mixed>
     */
    private function fila(int $inicial, int $altas, ?array $bajas, int $final, ?float $promedioOficial, bool $oficial): array
    {
        $bajas ??= ['total' => 0, 'voluntarias' => 0, 'involuntarias' => 0, 'sin_clasificar' => 0];
        $promedio = $oficial ? $promedioOficial : FormulaRotacion::plantillaPromedio($inicial, $final);

        return [
            'plantilla_inicial' => $inicial,
            'altas' => $altas,
            'bajas' => $bajas['total'],
            'bajas_voluntarias' => $bajas['voluntarias'],
            'bajas_involuntarias' => $bajas['involuntarias'],
            'bajas_sin_clasificar' => $bajas['sin_clasificar'],
            'plantilla_final' => $final,
            'plantilla_promedio' => $promedio !== null && $promedio > 0 ? $promedio : null,
            'rotacion_pct' => FormulaRotacion::indice($bajas['total'], $promedio),
        ];
    }

    /**
     * Eventos del motor cuya fecha de baja cae en [inicio, fin], por sucursal.
     *
     * @param  Collection<int, array<string, mixed>>  $eventos
     * @return array<int, array{total: int, voluntarias: int, involuntarias: int, sin_clasificar: int}>
     */
    private function bajasPorSucursal(Collection $eventos, CarbonImmutable $inicio, CarbonImmutable $fin): array
    {
        $salida = [];

        foreach ($eventos as $e) {
            if (! $e['fecha_baja'] instanceof CarbonImmutable || $e['fecha_baja']->lt($inicio) || $e['fecha_baja']->gt($fin)) {
                continue;
            }

            $id = (int) $e['sucursal_id'];
            $salida[$id] ??= ['total' => 0, 'voluntarias' => 0, 'involuntarias' => 0, 'sin_clasificar' => 0];
            $salida[$id]['total']++;
            $tipo = match ($e['clasificacion']) {
                ClasificacionSalida::Voluntaria => 'voluntarias',
                ClasificacionSalida::Involuntaria => 'involuntarias',
                default => 'sin_clasificar',
            };
            $salida[$id][$tipo]++;
        }

        return $salida;
    }

    /**
     * Bajas OFICIALES del mes por sucursal: las celdas del motor (individuales
     * más lo «sin desglose» del Excel histórico), igual que el Tablero.
     *
     * @param  Collection<int, int>  $sucursales
     * @return array<int, array{total: int, voluntarias: int, involuntarias: int, sin_clasificar: int}>
     */
    private function bajasOficiales(CarbonImmutable $inicio, CarbonImmutable $fin, Collection $sucursales): array
    {
        if ($sucursales->isEmpty()) {
            return [];
        }

        $filtros = new FiltrosRotacion('mes', $inicio->year, $inicio->month, $inicio, $fin, $sucursales, null, null, null, null, null, null, null, 'todas', $fin->lt($inicio->endOfMonth()->startOfDay()));
        $salida = [];

        foreach ($this->motor->celdas($filtros) as $celda) {
            if ($celda['sucursal_id'] <= 0 || $celda['total'] <= 0) {
                continue;
            }

            $salida[$celda['sucursal_id']] = [
                'total' => $celda['total'],
                'voluntarias' => $celda['voluntarias'],
                'involuntarias' => $celda['involuntarias'],
                'sin_clasificar' => $celda['sin_clasificar'],
            ];
        }

        return $salida;
    }

    /**
     * @param  list<array{total: int, voluntarias: int, involuntarias: int, sin_clasificar: int}>  $bajas
     * @return array{total: int, voluntarias: int, involuntarias: int, sin_clasificar: int}
     */
    private function sumarBajas(array $bajas): array
    {
        $suma = ['total' => 0, 'voluntarias' => 0, 'involuntarias' => 0, 'sin_clasificar' => 0];

        foreach ($bajas as $b) {
            foreach ($suma as $clave => $valor) {
                $suma[$clave] = $valor + $b[$clave];
            }
        }

        return $suma;
    }
}
