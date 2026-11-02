<?php

namespace App\Services\Bajas;

use App\Enums\SegmentoRotacion;
use App\Models\Colaborador;
use App\Models\PlantillaMensual;
use App\Models\RotacionPeriodo;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Rotacion\FormulaRotacion;
use App\Services\Rotacion\RotacionExcelLector;
use App\Services\Rotacion\RotacionMetaService;
use App\Services\Rotacion\RotacionMotorService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Plantilla e índice MENSUAL de rotación para el Tablero de RH, la pestaña de
 * Bajas y el módulo de Rotación (docs/ROTACION.md). Qué baja cuenta y en qué
 * mes lo decide RotacionMotorService (fuente única: bajas del sistema,
 * históricas individuales y totales del Excel, sin doble conteo); aquí solo
 * se pone el denominador:
 *
 *   Índice = bajas del mes ÷ plantilla promedio × 100 (FormulaRotacion).
 *
 * La plantilla del mes sale de plantilla_mensual; si falta, del Excel
 * (rotacion_periodos), y desde el inicio del módulo, reconstruida por fechas
 * de ingreso y baja (sucursal ACTUAL de cada persona, ver
 * PlantillaMensualService). Sin plantilla el índice es null, nunca 0.
 *
 * Segmentos (metas): Global = Corporativo + Sucursales en BAJAS, nunca en
 * índices. Cada mes se mide contra la meta vigente en ese mes.
 */
class TableroRotacionService
{
    /** @var array<string, Collection<int, RotacionPeriodo>> */
    private array $excelPorMes = [];

    /** @var array<string, Collection<int, PlantillaMensual>> */
    private array $fotosPorMes = [];

    /** @var Collection<int, Colaborador>|null */
    private ?Collection $personas = null;

    /** @var array<string, Collection<int|string, float>> */
    private array $reconstruidaPorMes = [];

    /** @var Collection<int, int>|null */
    private ?Collection $corporativos = null;

    public function __construct(
        private readonly RotacionMetaService $metas,
        private readonly PlantillaMensualService $plantillas,
        private readonly RotacionMotorService $motor,
    ) {}

    /**
     * Serie mensual para el Tablero de RH: bajas, plantilla, índice, meta y
     * semáforo de cada mes.
     *
     * @param  Collection<int, int>  $sucursales  Ya resueltas por el alcance de quien llama.
     * @return list<array<string, mixed>>
     */
    public function serieParaTablero(Collection $sucursales, CarbonImmutable $hasta, int $meses): array
    {
        $hasta = $hasta->startOfMonth();
        $segmento = $this->segmentoDe($sucursales);
        $serie = [];

        for ($m = $hasta->subMonths(max(1, $meses) - 1); $m <= $hasta; $m = $m->addMonth()) {
            $serie[] = $this->conMeta($this->mes($m, $sucursales), $m, $segmento);
        }

        return $serie;
    }

    /**
     * Rotación acumulada del año al mes dado: bajas ÷ promedio de las
     * plantillas promedio mensuales × 100.
     *
     * @param  Collection<int, int>  $sucursales  Ya resueltas por el alcance de quien llama.
     * @return array{anio: int, bajas: int, plantilla_promedio: float|null, indice: float|null, meses: int}
     */
    public function acumuladoParaTablero(Collection $sucursales, CarbonImmutable $hasta): array
    {
        $inicio = $hasta->startOfYear();
        $bajas = 0;
        $plantillas = [];

        for ($m = $inicio; $m <= $hasta->startOfMonth(); $m = $m->addMonth()) {
            $fila = $this->mes($m, $sucursales);
            $bajas += (int) $fila['bajas'];

            if ($fila['plantilla_promedio'] !== null) {
                $plantillas[] = $fila['plantilla_promedio'];
            }
        }

        $promedio = $plantillas === [] ? null : round(array_sum($plantillas) / count($plantillas), 2);

        return ['anio' => $inicio->year, 'bajas' => $bajas, 'plantilla_promedio' => $promedio, 'indice' => FormulaRotacion::indice($bajas, $promedio), 'meses' => count($plantillas)];
    }

    /**
     * Sucursales que el usuario puede consultar (misma regla que Rotación).
     *
     * @return Collection<int, int>
     */
    public function sucursalesVisibles(User $usuario): Collection
    {
        return $this->motor->sucursalesVisibles($usuario);
    }

    /**
     * Meta que aplica a un conjunto de sucursales según lo que contiene.
     *
     * @param  Collection<int, int>  $sucursales
     */
    public function segmentoDe(Collection $sucursales): SegmentoRotacion
    {
        if ($sucursales->isEmpty()) {
            return SegmentoRotacion::Global;
        }

        $corporativas = $sucursales->intersect($this->corporativos())->count();

        return match (true) {
            $corporativas === 0 => SegmentoRotacion::Sucursales,
            $corporativas === $sucursales->count() => SegmentoRotacion::Corporativo,
            default => SegmentoRotacion::Global,
        };
    }

    public function mesInicioSistema(): CarbonImmutable
    {
        return $this->motor->mesInicioSistema();
    }

    /**
     * Un mes: bajas (motor único), plantilla promedio, índice y de qué fuente
     * salió.
     *
     * @param  Collection<int, int>  $sucursales
     * @return array{periodo: string, etiqueta: string, bajas: int|null, plantilla_promedio: float|null, indice: float|null, fuente: string, en_curso: bool}
     */
    public function mes(CarbonImmutable $mes, Collection $sucursales): array
    {
        $mes = $mes->startOfMonth();

        if ($mes->isAfter(CarbonImmutable::today())) {
            return $this->filaMes($mes, null, null, 'sin_datos');
        }

        $bajas = $this->motor->bajasDelMes($mes, $sucursales);

        if ($bajas === null) {
            return $this->filaMes($mes, null, null, 'sin_datos');
        }

        $plantilla = $this->plantillaMes($mes, $sucursales);
        $fuente = $mes->lt($this->mesInicioSistema()) ? 'excel' : ($plantilla === null ? 'sin_plantilla' : 'sistema');

        return $this->filaMes($mes, $bajas, $plantilla, $fuente);
    }

    /**
     * Plantilla promedio del mes (suma de las sucursales): plantilla_mensual;
     * donde falte, la del Excel, y desde el corte, la reconstruida por fechas.
     *
     * @param  Collection<int, int>  $sucursales
     */
    public function plantillaMes(CarbonImmutable $mes, Collection $sucursales): ?float
    {
        $clave = $mes->format('Y-m');
        $this->fotosPorMes[$clave] ??= PlantillaMensual::query()->where('periodo', $clave)->whereNotNull('plantilla_promedio')->get();
        $fotos = $this->fotosPorMes[$clave]->whereIn('sucursal_id', $sucursales->all())->keyBy('sucursal_id');
        $excel = $this->periodosExcel($mes, $sucursales)->keyBy('sucursal_id');
        $reconstruida = $mes->gte($this->mesInicioSistema()) ? $this->plantillaReconstruida($mes) : collect();
        $total = 0.0;
        $alguna = false;

        foreach ($sucursales as $id) {
            if ($fotos->has($id)) {
                $total += (float) $fotos->get($id)?->plantilla_promedio;
                $alguna = true;
            } elseif ($excel->has($id)) {
                $p = $excel->get($id);
                $total += $p !== null ? FormulaRotacion::plantillaPromedio($p->plantilla_inicial, $p->plantilla_final) : 0;
                $alguna = true;
            } elseif ($reconstruida->has($id)) {
                $total += (float) $reconstruida->get($id);
                $alguna = true;
            }
        }

        return $alguna && $total > 0 ? round($total, 2) : null;
    }

    /**
     * Plantilla promedio por sucursal desde las fechas de ingreso y baja. Del mes
     * en curso, el cierre es hoy.
     *
     * @return Collection<int|string, float>
     */
    private function plantillaReconstruida(CarbonImmutable $mes): Collection
    {
        return $this->reconstruidaPorMes[$mes->format('Y-m')] ??= (function () use ($mes): Collection {
            $this->personas ??= Colaborador::query()->withTrashed()
                ->whereNotNull('sucursal_principal_id')
                ->whereNotNull('fecha_ingreso')
                ->get(['id', 'sucursal_principal_id', 'fecha_ingreso', 'fecha_baja', 'estatus']);

            $alInicio = $this->plantillas->activosEn($this->personas, $mes->startOfMonth());
            $alCierre = $this->plantillas->activosEn($this->personas, $mes->endOfMonth()->startOfDay()->min(CarbonImmutable::today()));

            return $alInicio->keys()->merge($alCierre->keys())->unique()
                ->mapWithKeys(fn (int|string $id): array => [$id => FormulaRotacion::plantillaPromedio((int) ($alInicio[$id] ?? 0), (int) ($alCierre[$id] ?? 0))]);
        })();
    }

    /**
     * @param  Collection<int, int>  $sucursales
     * @return Collection<int, RotacionPeriodo>
     */
    private function periodosExcel(CarbonImmutable $mes, Collection $sucursales): Collection
    {
        $clave = $mes->format('Y-m');
        $this->excelPorMes[$clave] ??= RotacionPeriodo::query()->where('anio', $mes->year)->where('mes', $mes->month)->get();

        return $this->excelPorMes[$clave]->whereIn('sucursal_id', $sucursales->all())->values();
    }

    /**
     * @return array{periodo: string, etiqueta: string, bajas: int|null, plantilla_promedio: float|null, indice: float|null, fuente: string, en_curso: bool}
     */
    private function filaMes(CarbonImmutable $mes, ?int $bajas, ?float $plantilla, string $fuente): array
    {
        return [
            'periodo' => $mes->format('Y-m'),
            'etiqueta' => sprintf('%s %d', mb_substr(RotacionExcelLector::NOMBRES_MES[$mes->month], 0, 3), $mes->year),
            'bajas' => $bajas,
            'plantilla_promedio' => $plantilla,
            'indice' => FormulaRotacion::indice($bajas, $plantilla),
            'fuente' => $fuente,
            'en_curso' => $mes->isSameMonth(CarbonImmutable::today()),
        ];
    }

    /**
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    private function conMeta(array $fila, CarbonImmutable $mes, SegmentoRotacion $segmento): array
    {
        return [...$fila, 'meta' => $this->metas->paraMes($segmento, $mes), 'semaforo' => $this->metas->semaforo($segmento, $fila['indice'], $mes)];
    }

    /**
     * @return Collection<int, int>
     */
    private function corporativos(): Collection
    {
        return $this->corporativos ??= Sucursal::query()->where('es_corporativo', true)->pluck('id')->map(fn ($id): int => (int) $id)->values();
    }
}
