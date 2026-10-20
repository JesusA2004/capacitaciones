<?php

namespace App\Services\Bajas;

use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\PlantillaMensual;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Plantilla mensual por sucursal (denominador del índice de rotación,
 * docs/BAJAS_Y_CAUSALES.md):
 *
 *  - FOTO (fuente «foto»): el comando programado bajas:foto-plantilla
 *    cuenta a los colaboradores vigentes (activos / en incorporación) de
 *    cada sucursal el día 1 (activos_inicio) y el último día del mes
 *    (activos_cierre). Sin captura manual.
 *  - BACKFILL (fuente «reconstruida»): para meses sin foto, se reconstruye
 *    desde las fechas de ingreso y baja de los colaboradores (incluidos los
 *    dados de baja): activo en la fecha = ingresó en o antes de esa fecha y
 *    no tiene fecha de baja o su fecha de baja es posterior. Usa la sucursal
 *    ACTUAL de cada persona (no hay historial confiable de cambios de
 *    sucursal) y descarta a quien no tiene fecha de ingreso. Nunca pisa una
 *    foto tomada.
 *
 * plantilla_promedio = (activos_inicio + activos_cierre) / 2; si solo hay
 * uno de los dos, el promedio es ese valor.
 */
class PlantillaMensualService
{
    /**
     * Foto del día: inicio (día 1) o cierre (último día) del mes de $fecha.
     *
     * @return int sucursales registradas
     */
    public function foto(CarbonImmutable $fecha, string $momento): int
    {
        $conteos = Colaborador::query()
            ->whereIn('estatus', EstadoUsuario::valoresVigentes())
            ->whereNotNull('sucursal_principal_id')
            ->selectRaw('sucursal_principal_id as sucursal_id, count(*) as total')
            ->groupBy('sucursal_principal_id')
            ->pluck('total', 'sucursal_id');

        $periodo = $fecha->format('Y-m');
        $existentes = PlantillaMensual::query()->where('periodo', $periodo)->pluck('sucursal_id');
        $columna = $momento === 'inicio' ? 'activos_inicio' : 'activos_cierre';
        $sello = $momento === 'inicio' ? 'foto_inicio_en' : 'foto_cierre_en';
        $total = 0;

        DB::transaction(function () use ($conteos, $existentes, $periodo, $fecha, $columna, $sello, &$total): void {
            foreach ($conteos->keys()->merge($existentes)->unique() as $sucursalId) {
                $fila = PlantillaMensual::query()->firstOrNew(['periodo' => $periodo, 'sucursal_id' => (int) $sucursalId], ['anio' => $fecha->year, 'mes' => $fecha->month]);
                $fila->fill([$columna => (int) ($conteos[$sucursalId] ?? 0), $sello => now(), 'fuente' => PlantillaMensual::FUENTE_FOTO]);
                $this->promediar($fila);
                $fila->save();
                $total++;
            }
        });

        return $total;
    }

    /**
     * Reconstruye los meses [desde, hasta] que no tengan foto.
     *
     * @return array{meses: int, filas: int, sin_fecha_ingreso: int}
     */
    public function backfill(CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $personas = Colaborador::query()->withTrashed()
            ->whereNotNull('sucursal_principal_id')
            ->get(['id', 'sucursal_principal_id', 'fecha_ingreso', 'fecha_baja', 'estatus']);
        $sinIngreso = $personas->whereNull('fecha_ingreso')->count();
        $conIngreso = $personas->whereNotNull('fecha_ingreso')->values();
        $hoy = CarbonImmutable::today();
        $meses = 0;
        $filas = 0;

        for ($mes = $desde->startOfMonth(); $mes <= $hasta->startOfMonth(); $mes = $mes->addMonth()) {
            $inicio = $mes->startOfMonth();
            $cierre = $mes->endOfMonth()->startOfDay();

            if ($inicio->isAfter($hoy)) {
                break;
            }

            $alInicio = $this->activosEn($conIngreso, $inicio);
            $alCierre = $cierre->isAfter($hoy) ? null : $this->activosEn($conIngreso, $cierre);
            $periodo = $mes->format('Y-m');

            DB::transaction(function () use ($alInicio, $alCierre, $periodo, $mes, &$filas): void {
                $sucursales = $alInicio->keys()->merge($alCierre?->keys() ?? collect())->unique();

                foreach ($sucursales as $sucursalId) {
                    $fila = PlantillaMensual::query()->firstOrNew(['periodo' => $periodo, 'sucursal_id' => (int) $sucursalId], ['anio' => $mes->year, 'mes' => $mes->month, 'fuente' => PlantillaMensual::FUENTE_RECONSTRUIDA]);

                    // Una foto real manda: solo se llena lo que no se fotografió.
                    if ($fila->foto_inicio_en === null) {
                        $fila->activos_inicio = (int) ($alInicio[$sucursalId] ?? 0);
                    }

                    if ($fila->foto_cierre_en === null && $alCierre !== null) {
                        $fila->activos_cierre = (int) ($alCierre[$sucursalId] ?? 0);
                    }

                    if ($fila->foto_inicio_en === null && $fila->foto_cierre_en === null) {
                        $fila->fuente = PlantillaMensual::FUENTE_RECONSTRUIDA;
                    }

                    $this->promediar($fila);
                    $fila->save();
                    $filas++;
                }
            });

            $meses++;
        }

        return ['meses' => $meses, 'filas' => $filas, 'sin_fecha_ingreso' => $sinIngreso];
    }

    /**
     * Activos por sucursal en una fecha (reconstrucción por fechas).
     *
     * @param  Collection<int, Colaborador>  $personas
     * @return Collection<int|string, int>
     */
    public function activosEn(Collection $personas, CarbonImmutable $fecha): Collection
    {
        return $personas
            ->filter(fn (Colaborador $c): bool => $c->fecha_ingreso !== null
                && $c->fecha_ingreso->lte($fecha)
                && ($c->fecha_baja === null ? $c->estatus !== EstadoUsuario::Inactivo : $c->fecha_baja->gte($fecha)))
            ->countBy(fn (Colaborador $c): int => (int) $c->sucursal_principal_id);
    }

    private function promediar(PlantillaMensual $fila): void
    {
        $valores = array_values(array_filter([$fila->activos_inicio, $fila->activos_cierre], fn (?int $v): bool => $v !== null));
        $fila->plantilla_promedio = $valores === [] ? null : number_format(array_sum($valores) / count($valores), 2, '.', '');
    }
}
