<?php

namespace App\Services\Rotacion;

use App\Models\RotacionPeriodo;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Permisos\AlcanceModuloService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Consulta de la rotación cargada desde Excel (rotacion_periodos), acotada
 * al alcance del módulo «rotacion» del usuario (AlcanceModuloService →
 * AlcanceOrganizacionalService). Mismas fórmulas que el Excel de RH:
 *
 *  - índice del mes = bajas × 100 / plantilla final del mes (sumando las
 *    sucursales reportadas cuando son varias, como «ROTACIÓN MENSUAL
 *    SUCURSALES» del Excel);
 *  - promedio mensual = promedio de los índices de los meses con datos;
 *  - rotación acumulada = bajas de la ventana × 100 / plantilla final del
 *    último mes (equivale a la «ROTACIÓN ANUAL» del Excel en 12 meses).
 */
class RotacionConsultaService
{
    public function __construct(private readonly AlcanceModuloService $alcance) {}

    /**
     * @param  array{sucursal_id?: mixed, hasta?: mixed}  $filtros
     * @return array<string, mixed>
     */
    public function tablero(User $usuario, array $filtros = []): array
    {
        $sucursalId = is_numeric($filtros['sucursal_id'] ?? null) ? (int) $filtros['sucursal_id'] : null;
        $base = $this->consulta($usuario, $sucursalId);

        $hasta = $this->hasta($filtros['hasta'] ?? null, $base);
        $meses = (int) config('rotacion.meses_ventana', 12);
        $desde = $hasta->subMonths($meses - 1);

        $periodos = (clone $base)
            ->where(fn (Builder $q) => $q
                ->where('anio', '>', $desde->year)
                ->orWhere(fn (Builder $w) => $w->where('anio', $desde->year)->where('mes', '>=', $desde->month)))
            ->where(fn (Builder $q) => $q
                ->where('anio', '<', $hasta->year)
                ->orWhere(fn (Builder $w) => $w->where('anio', $hasta->year)->where('mes', '<=', $hasta->month)))
            ->with('sucursal:id,nombre')
            ->get();

        $porMes = $periodos->groupBy(fn (RotacionPeriodo $p): string => sprintf('%04d-%02d', $p->anio, $p->mes));
        $serie = [];

        for ($mes = $desde; $mes <= $hasta; $mes = $mes->addMonth()) {
            /** @var Collection<int, RotacionPeriodo> $delMes */
            $delMes = $porMes->get($mes->format('Y-m'), collect());
            $serie[] = $this->fila($delMes, $mes);
        }

        $conDatos = array_values(array_filter($serie, fn (array $f): bool => $f['sucursales'] > 0));
        $ultimo = $conDatos === [] ? null : $conDatos[count($conDatos) - 1];

        $porSucursal = $periodos->groupBy('sucursal_id')->map(function (Collection $grupo): array {
            $ordenados = $grupo->sortBy(fn (RotacionPeriodo $p): int => $p->anio * 100 + $p->mes)->values();
            /** @var RotacionPeriodo $ultimo */
            $ultimo = $ordenados->last();
            $bajas = (int) $ordenados->sum('bajas');
            $indices = $ordenados->map(fn (RotacionPeriodo $p): ?float => $p->indice === null ? null : (float) $p->indice)->filter(fn (?float $v): bool => $v !== null);

            return [
                'sucursal_id' => $ultimo->sucursal_id,
                'sucursal' => $ultimo->sucursal->nombre,
                'meses' => $ordenados->count(),
                'altas' => (int) $ordenados->sum('altas'),
                'bajas' => $bajas,
                'plantilla_final' => $ultimo->plantilla_final,
                'promedio_mensual' => $indices->isEmpty() ? null : round((float) $indices->avg(), 2),
                'rotacion_acumulada' => $ultimo->plantilla_final > 0 ? round($bajas * 100 / $ultimo->plantilla_final, 2) : null,
            ];
        })->sortByDesc(fn (array $f): float => (float) ($f['rotacion_acumulada'] ?? -1))->values()->all();

        $indices = array_values(array_filter(array_column($conDatos, 'indice'), fn ($v): bool => $v !== null));
        $bajas = array_sum(array_column($conDatos, 'bajas'));

        return [
            'ventana' => ['desde' => $desde->format('Y-m'), 'hasta' => $hasta->format('Y-m')],
            'meses' => $serie,
            'por_sucursal' => $porSucursal,
            'totales' => [
                'altas' => array_sum(array_column($conDatos, 'altas')),
                'bajas' => $bajas,
                'plantilla_final' => $ultimo['plantilla_final'] ?? null,
                'promedio_mensual' => $indices === [] ? null : round(array_sum($indices) / count($indices), 2),
                'rotacion_acumulada' => ($ultimo['plantilla_final'] ?? 0) > 0 ? round($bajas * 100 / $ultimo['plantilla_final'], 2) : null,
                'meses_con_datos' => count($conDatos),
            ],
        ];
    }

    /**
     * Sucursales con rotación cargada que el usuario puede consultar (para el filtro).
     *
     * @return list<array{id: int, nombre: string}>
     */
    public function sucursalesConDatos(User $usuario): array
    {
        $ids = $this->consulta($usuario, null)->distinct()->pluck('sucursal_id');

        return array_values(Sucursal::query()->whereIn('id', $ids)->orderBy('nombre')->get(['id', 'nombre'])
            ->map(fn (Sucursal $s): array => ['id' => $s->id, 'nombre' => $s->nombre])
            ->all());
    }

    /**
     * Periodos cargados de un año (para prellenar el formato).
     *
     * @return Collection<int, RotacionPeriodo>
     */
    public function periodosDelAnio(User $usuario, int $anio): Collection
    {
        return $this->consulta($usuario, null)->whereIn('anio', [$anio, $anio - 1])->get();
    }

    /**
     * @return Builder<RotacionPeriodo>
     */
    private function consulta(User $usuario, ?int $sucursalId): Builder
    {
        $query = $this->alcance->limitarPorSucursal(RotacionPeriodo::query(), $usuario, 'rotacion');

        if ($sucursalId !== null) {
            $query->where('sucursal_id', $sucursalId);
        }

        return $query;
    }

    /**
     * Último mes de la ventana: el pedido (AAAA-MM) o el último con datos.
     *
     * @param  Builder<RotacionPeriodo>  $base
     */
    private function hasta(mixed $pedido, Builder $base): CarbonImmutable
    {
        if (is_string($pedido) && preg_match('/^(\d{4})-(\d{2})$/', $pedido, $m) === 1 && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
            return CarbonImmutable::create((int) $m[1], (int) $m[2], 1);
        }

        $ultimo = (clone $base)->orderByDesc('anio')->orderByDesc('mes')->first(['anio', 'mes']);

        return $ultimo !== null
            ? CarbonImmutable::create($ultimo->anio, $ultimo->mes, 1)
            : CarbonImmutable::now()->startOfMonth();
    }

    /**
     * @param  Collection<int, RotacionPeriodo>  $delMes
     * @return array<string, mixed>
     */
    private function fila(Collection $delMes, CarbonImmutable $mes): array
    {
        $final = (int) $delMes->sum('plantilla_final');
        $bajas = (int) $delMes->sum('bajas');
        $voluntarias = $delMes->whereNotNull('bajas_voluntarias');
        $involuntarias = $delMes->whereNotNull('bajas_involuntarias');

        return [
            'clave' => $mes->format('Y-m'),
            'anio' => $mes->year,
            'mes' => $mes->month,
            'etiqueta' => sprintf('%s %d', mb_substr(RotacionExcelLector::NOMBRES_MES[$mes->month], 0, 3), $mes->year),
            'sucursales' => $delMes->count(),
            'plantilla_inicial' => (int) $delMes->sum('plantilla_inicial'),
            'altas' => (int) $delMes->sum('altas'),
            'bajas' => $bajas,
            'bajas_voluntarias' => $voluntarias->isEmpty() ? null : (int) $voluntarias->sum('bajas_voluntarias'),
            'bajas_involuntarias' => $involuntarias->isEmpty() ? null : (int) $involuntarias->sum('bajas_involuntarias'),
            'plantilla_final' => $final,
            'indice' => $delMes->isNotEmpty() && $final > 0 ? round($bajas * 100 / $final, 2) : null,
        ];
    }
}
