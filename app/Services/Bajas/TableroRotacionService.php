<?php

namespace App\Services\Bajas;

use App\Enums\EstadoUsuario;
use App\Enums\EstatusBaja;
use App\Enums\FormaSalida;
use App\Enums\SegmentoRotacion;
use App\Models\Baja;
use App\Models\CausalRotacion;
use App\Models\Colaborador;
use App\Models\PlantillaMensual;
use App\Models\Puesto;
use App\Models\RotacionPeriodo;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Rotacion\FormulaRotacion;
use App\Services\Rotacion\RotacionExcelLector;
use App\Services\Rotacion\RotacionMetaService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Motor único de rotación (docs/BAJAS_Y_CAUSALES.md, PDF Etapa 1 págs. 12-13 y
 * 17): lo consumen la pestaña Rotación de Bajas, el Tablero de RH y las
 * exportaciones. Fórmula: App\Services\Rotacion\FormulaRotacion.
 *
 * Fuentes y cómo se combinan, sin contar una baja dos veces:
 *  - Meses ANTERIORES al corte (config bajas.tablero.mes_inicio_sistema):
 *    agregados del Excel ya importado (rotacion_periodos). Un mes sin Excel es
 *    «sin datos»: no se reconstruye historia inexistente.
 *  - Desde el corte: bajas del SISTEMA en el mes de su último día laborado, aunque
 *    el expediente siga en trámite. No cuentan: «No procede», estatus del flujo
 *    anterior, movimientos internos (RN-14) ni un último día que aún no llega. La
 *    plantilla sale de plantilla_mensual; si falta, del Excel, y si tampoco, de
 *    las fechas de ingreso y baja (sucursal ACTUAL de cada persona, ver
 *    PlantillaMensualService).
 *  - El desglose por forma, causal, puesto y antigüedad SOLO existe para bajas del
 *    sistema: el Excel trae totales, a lo sumo voluntarias/involuntarias.
 *
 * Segmentos: Global = Corporativo + Sucursales en BAJAS, nunca en índices: cada
 * índice divide entre la plantilla de su segmento, así que el global no es la suma
 * de los índices (ver resumen.comprobacion). Cada mes se mide contra la meta vigente
 * en ese mes (RotacionMetaService). Alcance: AlcanceOrganizacionalService.
 */
class TableroRotacionService
{
    public const VISTAS = ['global', 'corporativo', 'sucursales', 'por_sucursal', 'por_causales'];

    /**
     * Memoria por petición: periodos del Excel y fotos de plantilla por mes,
     * y las bajas cerradas del sistema ya cargadas (se filtran en memoria).
     *
     * @var array<string, Collection<int, RotacionPeriodo>>
     */
    private array $excelPorMes = [];

    /** @var array<string, Collection<int, PlantillaMensual>> */
    private array $fotosPorMes = [];

    /** @var Collection<int, Baja>|null */
    private ?Collection $computables = null;

    /** @var Collection<int, Colaborador>|null */
    private ?Collection $personas = null;

    /** @var array<string, Collection<int|string, float>> */
    private array $reconstruidaPorMes = [];

    /** @var Collection<int, int>|null */
    private ?Collection $corporativos = null;

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly BajaDocumentosService $documentos,
        private readonly RotacionMetaService $metas,
        private readonly PlantillaMensualService $plantillas,
    ) {}

    /**
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    public function tablero(User $usuario, array $entrada): array
    {
        $filtros = $this->filtros($usuario, $entrada);
        $sucursalesScope = $this->sucursalesVisibles($usuario);
        $sucursales = $this->sucursalesDeVista($filtros, $sucursalesScope);
        $desde = CarbonImmutable::createFromFormat('!Y-m', $filtros['desde']) ?: CarbonImmutable::now()->startOfYear();
        $hasta = CarbonImmutable::createFromFormat('!Y-m', $filtros['hasta']) ?: CarbonImmutable::now()->startOfMonth();
        $segmento = $this->segmentoDe($sucursales);

        $bajasRango = $this->bajasSistema($sucursales, $filtros, $desde, $hasta->endOfMonth());
        $serie = $this->serie($desde, $hasta, $sucursales, $filtros, $segmento);
        $mes = $this->conMeta($this->mes($hasta, $sucursales, $filtros), $hasta, $segmento);
        $anterior = $this->mes($hasta->subMonth(), $sucursales, $filtros);
        $causales = $this->causales($bajasRango);
        $promedio = $this->promedioMensual($serie);

        return [
            'filtros' => $filtros,
            'mes_inicio_sistema' => $this->mesInicioSistema()->format('Y-m'),
            'segmento' => ['clave' => $segmento->value, 'etiqueta' => $segmento->etiqueta()],
            'resumen' => [
                'mes' => $mes,
                'comparativo' => ['indice_anterior' => $anterior['indice'], ...$this->variacion($mes['indice'], $anterior['indice'])],
                'promedio_mensual' => ['indice' => $promedio['indice'], 'meses' => $promedio['meses'], 'semaforo' => $this->metas->semaforo($segmento, $promedio['indice'], $hasta)],
                'acumulado' => $this->acumulado($hasta->startOfYear(), $hasta, $sucursales, $filtros),
                'rango' => $this->acumuladoRango($desde, $hasta, $sucursales, $filtros),
                'voluntaria' => $this->voluntaria($serie),
                'por_forma' => $this->porForma($bajasRango, $this->plantillaPromedioRango($desde, $hasta, $sucursales, $filtros)),
                'rotacion_temprana' => $this->rotacionTemprana($bajasRango),
                'causal_principal' => $causales[0] ?? null,
                'antiguedad_promedio_dias' => $this->antiguedadPromedio($bajasRango),
                'antiguedad_por_forma' => $this->antiguedadPorForma($bajasRango),
                'calidad_expediente' => $this->calidad($bajasRango),
                'bajas_sistema' => $bajasRango->count(),
                'comprobacion' => $this->comprobacion($desde, $hasta, $sucursalesScope, $filtros),
            ],
            'tendencia' => $serie,
            'causales' => $causales,
            'sucursales' => $this->porSucursal($sucursales, $filtros, $desde, $hasta, $bajasRango),
            'puestos' => $this->porPuesto($bajasRango, $desde, $hasta, $sucursales),
            'metas' => $this->metas->panel($hasta),
            'opciones' => $this->opciones($sucursalesScope),
        ];
    }

    /**
     * Serie mensual para el Tablero de RH: mismos datos y misma definición que
     * la pestaña Rotación, sin filtros de desglose.
     *
     * @param  Collection<int, int>  $sucursales  Ya resueltas por el alcance de quien llama.
     * @return list<array<string, mixed>>
     */
    public function serieParaTablero(Collection $sucursales, CarbonImmutable $hasta, int $meses): array
    {
        $hasta = $hasta->startOfMonth();
        $filtros = ['forma' => null, 'causal_id' => null, 'puesto_id' => null];

        return $this->serie($hasta->subMonths(max(1, $meses) - 1), $hasta, $sucursales, $filtros, $this->segmentoDe($sucursales));
    }

    /**
     * Rotación acumulada del año al mes dado, con la misma definición de la pestaña Rotación.
     *
     * @param  Collection<int, int>  $sucursales  Ya resueltas por el alcance de quien llama.
     * @return array{anio: int, bajas: int, plantilla_promedio: float|null, indice: float|null, meses: int}
     */
    public function acumuladoParaTablero(Collection $sucursales, CarbonImmutable $hasta): array
    {
        return $this->acumulado($hasta->startOfYear(), $hasta->startOfMonth(), $sucursales, ['forma' => null, 'causal_id' => null, 'puesto_id' => null]);
    }

    /**
     * Tabla exportable (Excel/PDF) de la vista activa, con sus filtros.
     *
     * @param  array<string, mixed>  $entrada
     * @return array{titulo: string, columnas: list<string>, filas: list<list<string|int|float|null>>}
     */
    public function exportable(User $usuario, array $entrada): array
    {
        $datos = $this->tablero($usuario, $entrada);
        $f = $datos['filtros'];

        [$columnas, $filas] = match ($f['vista']) {
            'por_sucursal' => $this->tablaPorSucursal($datos),
            'por_causales' => $this->tablaPorCausales($datos),
            default => $this->tablaMensual($datos),
        };

        $etiquetas = array_filter([
            $this->etiquetaVista($f['vista']),
            sprintf('%s a %s', $f['desde'], $f['hasta']),
            $f['sucursal_id'] !== null ? Sucursal::query()->where('id', $f['sucursal_id'])->value('nombre') : null,
            $f['puesto_id'] !== null ? Puesto::query()->where('id', $f['puesto_id'])->value('nombre') : null,
            $f['forma'] !== null ? FormaSalida::tryFrom($f['forma'])?->etiqueta() : null,
        ]);

        return [
            'titulo' => sprintf('Rotación de personal · %s', implode(' · ', $etiquetas)),
            'columnas' => $columnas,
            'filas' => $filas,
        ];
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array{0: list<string>, 1: list<list<string|int|float|null>>}
     */
    private function tablaMensual(array $datos): array
    {
        $filas = [];

        foreach ($datos['tendencia'] as $m) {
            $filas[] = [$m['etiqueta'].($m['en_curso'] ? ' (en curso)' : ''), $m['por_forma']['RV'], $m['por_forma']['NR'], $m['por_forma']['RE'], $m['por_forma']['OT'], $m['sin_desglose'], $m['bajas'], $m['plantilla_promedio'], $m['indice'], $m['meta']['verde_hasta'] ?? null, $this->etiquetaSemaforo($m['semaforo'])];
        }

        $resumen = $datos['resumen'];
        $filas[] = ['Total del periodo', null, null, null, null, null, $resumen['rango']['bajas'], $resumen['rango']['plantilla_promedio'], $resumen['rango']['indice'], null, null];

        return [['Mes', 'Renuncia', 'No renovación', 'Rescisión', 'Otras', 'Sin desglose (Excel)', 'Bajas', 'Plantilla promedio', 'Índice %', 'Meta verde %', 'Semáforo'], $filas];
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array{0: list<string>, 1: list<list<string|int|float|null>>}
     */
    private function tablaPorSucursal(array $datos): array
    {
        $filas = [];

        foreach ($datos['sucursales'] as $s) {
            $filas[] = [
                $s['sucursal'], $s['plantilla_promedio'], $s['bajas'], $s['promedio_mensual'], $this->etiquetaSemaforo($s['semaforo']), $s['indice_mes'], $s['delta'],
                $s['conteos']['RV'], $s['conteos']['NR'], $s['conteos']['RE'], $s['conteos']['OT'], $s['conteos']['sin_desglose'],
                $s['causal_principal'], $s['rotacion_temprana'],
            ];
        }

        return [['Sucursal', 'Plantilla promedio', 'Bajas', 'Promedio mensual %', 'Semáforo', 'Índice del mes %', 'Vs. mes anterior (pts.)', 'RV', 'NR', 'RE', 'OT', 'Sin desglose (Excel)', 'Causal principal', 'Rotación temprana %'], $filas];
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array{0: list<string>, 1: list<list<string|int|float|null>>}
     */
    private function tablaPorCausales(array $datos): array
    {
        $filas = [];

        foreach ($datos['causales'] as $c) {
            $filas[] = [$c['clave'], $c['nombre'], FormaSalida::tryFrom((string) $c['forma'])?->etiqueta(), $c['bajas'], $c['porcentaje']];
        }

        return [['Clave', 'Causal', 'Forma de salida', 'Bajas', '% del periodo'], $filas];
    }

    private function etiquetaVista(string $vista): string
    {
        return match ($vista) {
            'corporativo' => 'Corporativo',
            'sucursales' => 'Todas las sucursales',
            'por_sucursal' => 'Por sucursal',
            'por_causales' => 'Por causales',
            default => 'Global',
        };
    }

    private function etiquetaSemaforo(?string $semaforo): ?string
    {
        return match ($semaforo) {
            'verde' => 'Verde',
            'ambar' => 'Ámbar',
            'rojo' => 'Rojo',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return array{vista: string, desde: string, hasta: string, sucursal_id: int|null, puesto_id: int|null, forma: string|null, causal_id: int|null}
     */
    public function filtros(User $usuario, array $entrada): array
    {
        $mesValido = fn (mixed $v): ?string => is_string($v) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v) === 1 ? $v : null;
        $hasta = $mesValido($entrada['hasta'] ?? null) ?? CarbonImmutable::now()->format('Y-m');
        $desde = $mesValido($entrada['desde'] ?? null) ?? substr($hasta, 0, 4).'-01';

        if ($desde > $hasta) {
            [$desde, $hasta] = [$hasta, $desde];
        }

        $sucursalId = is_numeric($entrada['sucursal_id'] ?? null) ? (int) $entrada['sucursal_id'] : null;

        if ($sucursalId !== null && ! $this->sucursalesVisibles($usuario)->contains($sucursalId)) {
            $sucursalId = null;
        }

        $vista = (string) ($entrada['vista'] ?? 'global');

        return [
            'vista' => in_array($vista, self::VISTAS, true) ? $vista : 'global',
            'desde' => $desde,
            'hasta' => $hasta,
            'sucursal_id' => $sucursalId,
            'puesto_id' => is_numeric($entrada['puesto_id'] ?? null) ? (int) $entrada['puesto_id'] : null,
            'forma' => FormaSalida::tryFrom((string) ($entrada['forma'] ?? ''))?->value,
            'causal_id' => is_numeric($entrada['causal_id'] ?? null) ? (int) $entrada['causal_id'] : null,
        ];
    }

    /**
     * Sucursales que el usuario puede consultar en el tablero.
     *
     * @return Collection<int, int>
     */
    public function sucursalesVisibles(User $usuario): Collection
    {
        if ($usuario->can('bajas.gestionar') || $this->alcance->tieneAlcanceGlobal($usuario)) {
            return Sucursal::query()->pluck('id')->map(fn ($id): int => (int) $id)->values();
        }

        return $this->alcance->sucursalesVisiblesIds($usuario)->map(fn ($id): int => (int) $id)->values();
    }

    /**
     * Sucursales de la vista dentro del alcance. El filtro de sucursal no aplica
     * a Corporativo, que es su propia ubicación.
     *
     * @param  array<string, mixed>  $filtros
     * @param  Collection<int, int>  $scope
     * @return Collection<int, int>
     */
    private function sucursalesDeVista(array $filtros, Collection $scope): Collection
    {
        $corporativos = $this->corporativos();

        return match ($filtros['vista']) {
            'corporativo' => $scope->intersect($corporativos)->values(),
            'sucursales' => $this->filtrarSucursal($scope->diff($corporativos)->values(), $filtros),
            default => $this->filtrarSucursal($scope, $filtros),
        };
    }

    /**
     * @param  Collection<int, int>  $ids
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, int>
     */
    private function filtrarSucursal(Collection $ids, array $filtros): Collection
    {
        return $filtros['sucursal_id'] !== null ? $ids->filter(fn (int $id): bool => $id === $filtros['sucursal_id'])->values() : $ids;
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

    /**
     * @return Collection<int, int>
     */
    private function corporativos(): Collection
    {
        return $this->corporativos ??= Sucursal::query()->where('es_corporativo', true)->pluck('id')->map(fn ($id): int => (int) $id)->values();
    }

    public function mesInicioSistema(): CarbonImmutable
    {
        $mes = (string) config('bajas.tablero.mes_inicio_sistema', '2026-10');

        return CarbonImmutable::createFromFormat('!Y-m', $mes) ?: CarbonImmutable::create(2026, 10, 1);
    }

    /**
     * Un mes: bajas, plantilla promedio, índice y de qué fuente salió.
     *
     * @param  Collection<int, int>  $sucursales
     * @param  array<string, mixed>  $filtros
     * @return array{periodo: string, etiqueta: string, bajas: int|null, plantilla_promedio: float|null, indice: float|null, fuente: string, en_curso: bool}
     */
    public function mes(CarbonImmutable $mes, Collection $sucursales, array $filtros): array
    {
        $mes = $mes->startOfMonth();
        $conDesglose = $filtros['forma'] !== null || $filtros['causal_id'] !== null || $filtros['puesto_id'] !== null;

        if ($mes->lt($this->mesInicioSistema())) {
            $periodos = $this->periodosExcel($mes, $sucursales);

            if ($periodos->isEmpty()) {
                return $this->filaMes($mes, null, null, 'sin_datos');
            }

            $plantilla = (float) $periodos->sum(fn (RotacionPeriodo $p): float => FormulaRotacion::plantillaPromedio($p->plantilla_inicial, $p->plantilla_final));

            // El Excel no trae forma, causal ni puesto: con esos filtros no hay cifra.
            return $conDesglose
                ? $this->filaMes($mes, null, $plantilla, 'excel_sin_desglose')
                : $this->filaMes($mes, (int) $periodos->sum('bajas'), $plantilla, 'excel');
        }

        // Un mes que aún no empieza no tiene datos: no es 0%.
        if ($mes->isAfter(CarbonImmutable::today())) {
            return $this->filaMes($mes, null, null, 'sin_datos');
        }

        $bajas = $this->bajasSistema($sucursales, $filtros, $mes, $mes->endOfMonth())->count();
        $plantilla = $filtros['puesto_id'] !== null
            ? $this->plantillaPuesto($mes, $sucursales, (int) $filtros['puesto_id'])
            : $this->plantillaMes($mes, $sucursales);

        return $this->filaMes($mes, $bajas, $plantilla, $plantilla === null ? 'sin_plantilla' : 'sistema');
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

        return $alguna ? round($total, 2) : null;
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
     * Bajas que cuentan para rotación: efectivas por su último día, aunque el
     * expediente siga en trámite.
     *
     * @param  Collection<int, int>  $sucursales
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, Baja>
     */
    private function bajasSistema(Collection $sucursales, array $filtros, CarbonImmutable $desde, CarbonImmutable $hasta): Collection
    {
        $inicio = $this->mesInicioSistema();
        $desde = $desde->lt($inicio) ? $inicio : $desde;

        if ($desde->gt($hasta)) {
            return collect();
        }

        // Se cargan una vez por petición y se filtran en memoria.
        $this->computables ??= Baja::query()
            ->with(['causal', 'sucursal:id,nombre', 'puesto:id,nombre', 'cierre', 'documentos'])
            ->whereIn('estatus', [EstatusBaja::EnRevisionRh->value, EstatusBaja::EnRevisionJuridico->value, EstatusBaja::Cerrada->value])
            ->where('es_rotacion', true)
            ->whereNotNull('fecha_baja')
            ->whereDate('fecha_baja', '>=', $inicio->toDateString())
            ->whereDate('fecha_baja', '<=', CarbonImmutable::today()->toDateString())
            ->get();

        $ids = $sucursales->all();
        $desdeTexto = $desde->toDateString();
        $hastaTexto = $hasta->toDateString();

        return $this->computables
            ->filter(fn (Baja $b): bool => in_array($b->sucursal_id, $ids, true)
                && $b->fecha_baja !== null
                && $b->fecha_baja->toDateString() >= $desdeTexto
                && $b->fecha_baja->toDateString() <= $hastaTexto
                && ($filtros['forma'] === null || $b->forma_salida?->value === $filtros['forma'])
                && ($filtros['causal_id'] === null || $b->causal_rotacion_id === $filtros['causal_id'])
                && ($filtros['puesto_id'] === null || $b->puesto_id === $filtros['puesto_id']))
            ->values();
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
            'indice' => $this->indice($bajas, $plantilla),
            'fuente' => $fuente,
            'en_curso' => $mes->isSameMonth(CarbonImmutable::today()),
        ];
    }

    private function indice(?int $bajas, ?float $plantilla): ?float
    {
        return FormulaRotacion::indice($bajas, $plantilla);
    }

    /**
     * @param  Collection<int, int>  $sucursales
     * @param  array<string, mixed>  $filtros
     * @return array{anio: int, bajas: int, plantilla_promedio: float|null, indice: float|null, meses: int}
     */
    private function acumulado(CarbonImmutable $inicio, CarbonImmutable $hasta, Collection $sucursales, array $filtros): array
    {
        $bajas = 0;
        $plantillas = [];

        for ($m = $inicio; $m <= $hasta; $m = $m->addMonth()) {
            $fila = $this->mes($m, $sucursales, $filtros);

            if ($fila['bajas'] !== null) {
                $bajas += $fila['bajas'];
            }

            if ($fila['plantilla_promedio'] !== null) {
                $plantillas[] = $fila['plantilla_promedio'];
            }
        }

        $promedio = $plantillas === [] ? null : round(array_sum($plantillas) / count($plantillas), 2);

        return ['anio' => $inicio->year, 'bajas' => $bajas, 'plantilla_promedio' => $promedio, 'indice' => $this->indice($bajas, $promedio), 'meses' => count($plantillas)];
    }

    /**
     * @param  Collection<int, int>  $sucursales
     * @param  array<string, mixed>  $filtros
     */
    private function plantillaPromedioRango(CarbonImmutable $desde, CarbonImmutable $hasta, Collection $sucursales, array $filtros): ?float
    {
        $valores = [];

        for ($m = $desde->max($this->mesInicioSistema()); $m <= $hasta; $m = $m->addMonth()) {
            $p = $filtros['puesto_id'] !== null ? $this->plantillaPuesto($m, $sucursales, (int) $filtros['puesto_id']) : $this->plantillaMes($m, $sucursales);

            if ($p !== null) {
                $valores[] = $p;
            }
        }

        return $valores === [] ? null : round(array_sum($valores) / count($valores), 2);
    }

    /**
     * Un punto por mes del rango: pila por forma, índice, meta y semáforo.
     *
     * @param  Collection<int, int>  $sucursales
     * @param  array<string, mixed>  $filtros
     * @return list<array<string, mixed>>
     */
    private function serie(CarbonImmutable $desde, CarbonImmutable $hasta, Collection $sucursales, array $filtros, SegmentoRotacion $segmento): array
    {
        $serie = [];

        for ($m = $desde; $m <= $hasta; $m = $m->addMonth()) {
            $fila = $this->conMeta($this->mes($m, $sucursales, $filtros), $m, $segmento);
            $delMes = $this->bajasSistema($sucursales, $filtros, $m, $m->endOfMonth());
            $porForma = [];

            foreach (FormaSalida::cases() as $forma) {
                $porForma[$forma->value] = $delMes->filter(fn (Baja $b): bool => $b->forma_salida === $forma)->count();
            }

            $excel = $fila['fuente'] === 'excel' ? $this->periodosExcel($m, $sucursales) : collect();

            $serie[] = [
                ...$fila,
                'por_forma' => $porForma,
                'sin_desglose' => $fila['fuente'] === 'excel' ? (int) $fila['bajas'] : 0,
                'excel_voluntarias' => (int) $excel->sum('bajas_voluntarias'),
                'excel_involuntarias' => (int) $excel->sum('bajas_involuntarias'),
            ];
        }

        return $serie;
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
     * Promedio de los índices mensuales con dato. El mes en curso no entra si
     * hay meses cerrados: todavía puede cambiar.
     *
     * @param  list<array<string, mixed>>  $serie
     * @return array{indice: float|null, meses: int}
     */
    private function promedioMensual(array $serie): array
    {
        $conDato = array_values(array_filter($serie, fn (array $m): bool => $m['indice'] !== null));
        $cerrados = array_values(array_filter($conDato, fn (array $m): bool => ! $m['en_curso']));
        $base = $cerrados !== [] ? $cerrados : $conDato;

        return [
            'indice' => $base === [] ? null : round(array_sum(array_column($base, 'indice')) / count($base), 2),
            'meses' => count($base),
        ];
    }

    /**
     * @return array{delta: float|null, tendencia: string|null}
     */
    private function variacion(?float $actual, ?float $anterior): array
    {
        if ($actual === null || $anterior === null) {
            return ['delta' => null, 'tendencia' => null];
        }

        return ['delta' => round($actual - $anterior, 2), 'tendencia' => match ($actual <=> $anterior) {
            1 => 'sube',
            -1 => 'baja',
            default => 'igual',
        }];
    }

    /**
     * Voluntaria (RV) contra involuntaria (NR y RE). Otras terminaciones no son
     * de ninguno de los dos, y el Excel solo aporta lo que traiga clasificado.
     *
     * @param  list<array<string, mixed>>  $serie
     * @return array{voluntarias: int, involuntarias: int, otras: int, sin_clasificar: int, total: int, porcentaje_voluntaria: float|null}
     */
    private function voluntaria(array $serie): array
    {
        $voluntarias = 0;
        $involuntarias = 0;
        $otras = 0;
        $total = 0;

        foreach ($serie as $m) {
            $voluntarias += (int) $m['por_forma']['RV'] + (int) $m['excel_voluntarias'];
            $involuntarias += (int) $m['por_forma']['NR'] + (int) $m['por_forma']['RE'] + (int) $m['excel_involuntarias'];
            $otras += (int) $m['por_forma']['OT'];
            $total += (int) $m['bajas'];
        }

        $clasificadas = $voluntarias + $involuntarias;

        return [
            'voluntarias' => $voluntarias,
            'involuntarias' => $involuntarias,
            'otras' => $otras,
            'sin_clasificar' => max(0, $total - $clasificadas - $otras),
            'total' => $total,
            'porcentaje_voluntaria' => $clasificadas > 0 ? round($voluntarias * 100 / $clasificadas, 1) : null,
        ];
    }

    /**
     * Global = Corporativo + Sucursales, en bajas y con los mismos filtros.
     *
     * @param  Collection<int, int>  $scope
     * @param  array<string, mixed>  $filtros
     * @return array{global: int, corporativo: int, sucursales: int, cuadra: bool}
     */
    private function comprobacion(CarbonImmutable $desde, CarbonImmutable $hasta, Collection $scope, array $filtros): array
    {
        $corporativos = $this->corporativos();
        $global = $this->acumuladoRango($desde, $hasta, $scope, $filtros)['bajas'];
        $corporativo = $this->acumuladoRango($desde, $hasta, $scope->intersect($corporativos)->values(), $filtros)['bajas'];
        $sucursales = $this->acumuladoRango($desde, $hasta, $scope->diff($corporativos)->values(), $filtros)['bajas'];

        return ['global' => $global, 'corporativo' => $corporativo, 'sucursales' => $sucursales, 'cuadra' => $global === $corporativo + $sucursales];
    }

    /**
     * @param  Collection<int, Baja>  $bajas
     * @return list<array{forma: string, etiqueta: string, token: string, bajas: int, porcentaje: float|null, indice: float|null}>
     */
    private function porForma(Collection $bajas, ?float $plantilla): array
    {
        $total = $bajas->count();

        return array_map(function (FormaSalida $forma) use ($bajas, $total, $plantilla): array {
            $n = $bajas->filter(fn (Baja $b): bool => $b->forma_salida === $forma)->count();

            return [
                'forma' => $forma->value,
                'etiqueta' => $forma->etiqueta(),
                'token' => $forma->token(),
                'bajas' => $n,
                'porcentaje' => $total > 0 ? round($n * 100 / $total, 1) : null,
                'indice' => $this->indice($n, $plantilla),
            ];
        }, FormaSalida::cases());
    }

    /**
     * @param  Collection<int, Baja>  $bajas
     * @return array{bajas: int, porcentaje: float|null}
     */
    private function rotacionTemprana(Collection $bajas): array
    {
        $limite = (int) config('bajas.reglas.dias_rotacion_temprana', 90);
        $tempranas = $bajas->filter(fn (Baja $b): bool => $b->antiguedad_dias !== null && $b->antiguedad_dias < $limite)->count();

        return ['bajas' => $tempranas, 'porcentaje' => $bajas->isNotEmpty() ? round($tempranas * 100 / $bajas->count(), 1) : null];
    }

    /**
     * @param  Collection<int, Baja>  $bajas
     */
    private function antiguedadPromedio(Collection $bajas): ?int
    {
        $conAntiguedad = $bajas->whereNotNull('antiguedad_dias');

        return $conAntiguedad->isEmpty() ? null : (int) round((float) $conAntiguedad->avg('antiguedad_dias'));
    }

    /**
     * @param  Collection<int, Baja>  $bajas
     * @return list<array{forma: string, etiqueta: string, dias: int|null}>
     */
    private function antiguedadPorForma(Collection $bajas): array
    {
        return array_map(fn (FormaSalida $f): array => [
            'forma' => $f->value,
            'etiqueta' => $f->etiqueta(),
            'dias' => $this->antiguedadPromedio($bajas->filter(fn (Baja $b): bool => $b->forma_salida === $f)),
        ], FormaSalida::cases());
    }

    /**
     * Calidad del expediente = cerradas con todos los documentos ÷ cerradas.
     *
     * @param  Collection<int, Baja>  $bajas
     * @return array{cerradas: int, completas: int, porcentaje: float|null}
     */
    private function calidad(Collection $bajas): array
    {
        $cerradas = $bajas->filter(fn (Baja $b): bool => $b->estatus === EstatusBaja::Cerrada);
        $completas = $cerradas->filter(fn (Baja $b): bool => $this->documentos->faltantes($b) === [])->count();

        return ['cerradas' => $cerradas->count(), 'completas' => $completas, 'porcentaje' => $cerradas->isNotEmpty() ? round($completas * 100 / $cerradas->count(), 1) : null];
    }

    /**
     * Top de causales (número y % sobre el total del periodo, desc).
     *
     * @param  Collection<int, Baja>  $bajas
     * @return list<array{clave: string|null, nombre: string, forma: string|null, token: string|null, alerta: string|null, bajas: int, porcentaje: float}>
     */
    private function causales(Collection $bajas): array
    {
        $total = max(1, $bajas->count());

        return array_values($bajas->groupBy(fn (Baja $b): string => (string) ($b->causal_rotacion_id ?? 'sin'))
            ->map(function (Collection $grupo) use ($total): array {
                $primera = $grupo->first();
                $causal = $primera instanceof Baja ? $primera->causal : null;
                $forma = $causal?->forma() ?? ($primera instanceof Baja ? $primera->forma_salida : null);

                return [
                    'clave' => $causal?->clave,
                    'nombre' => $causal->nombre ?? 'Sin causal',
                    'forma' => $forma?->value,
                    'token' => $forma?->token(),
                    'alerta' => $causal?->alerta,
                    'bajas' => $grupo->count(),
                    'porcentaje' => round($grupo->count() * 100 / $total, 1),
                ];
            })
            ->sortByDesc('bajas')
            ->values()
            ->all());
    }

    /**
     * Tabla por sucursal: promedio mensual con semáforo (el acumulado sin
     * contexto puede pasar de 100%), comparativo y las bajas que la componen.
     * Corporativo va al final y se mide con su propia meta.
     *
     * @param  Collection<int, int>  $sucursales
     * @param  array<string, mixed>  $filtros
     * @param  Collection<int, Baja>  $bajasRango
     * @return list<array<string, mixed>>
     */
    private function porSucursal(Collection $sucursales, array $filtros, CarbonImmutable $desde, CarbonImmutable $hasta, Collection $bajasRango): array
    {
        $nombres = Sucursal::query()->whereIn('id', $sucursales->all())->pluck('nombre', 'id');
        $corporativos = $this->corporativos();
        $filas = [];

        foreach ($sucursales as $id) {
            $una = collect([$id]);
            $segmento = $this->segmentoDe($una);
            $bajasSucursal = $bajasRango->where('sucursal_id', $id)->values();
            $actual = $this->mes($hasta, $una, $filtros);
            $anterior = $this->mes($hasta->subMonth(), $una, $filtros);
            $acumulado = $this->acumuladoRango($desde, $hasta, $una, $filtros);

            if ($acumulado['bajas'] === 0 && $acumulado['plantilla_promedio'] === null) {
                continue;
            }

            $serie = $this->serie($desde, $hasta, $una, $filtros, $segmento);
            $promedio = $this->promedioMensual($serie)['indice'];
            $conteos = ['sin_desglose' => (int) array_sum(array_column($serie, 'sin_desglose'))];
            $porForma = [];

            foreach (FormaSalida::cases() as $forma) {
                $conteos[$forma->value] = $bajasSucursal->filter(fn (Baja $b): bool => $b->forma_salida === $forma)->count();
                $porForma[$forma->value] = $bajasSucursal->isNotEmpty() ? round($conteos[$forma->value] * 100 / $bajasSucursal->count(), 1) : null;
            }

            $principal = $this->causales($bajasSucursal)[0] ?? null;

            $filas[] = [
                'sucursal_id' => $id,
                'sucursal' => (string) ($nombres[$id] ?? sprintf('Sucursal #%d', $id)),
                'es_corporativo' => $corporativos->contains($id),
                'bajas' => $acumulado['bajas'],
                'plantilla_promedio' => $acumulado['plantilla_promedio'],
                'indice' => $acumulado['indice'],
                'promedio_mensual' => $promedio,
                'semaforo' => $this->metas->semaforo($segmento, $promedio, $hasta),
                'indice_mes' => $actual['indice'],
                'indice_mes_anterior' => $anterior['indice'],
                ...$this->variacion($actual['indice'], $anterior['indice']),
                'conteos' => $conteos,
                'por_forma' => $porForma,
                'causal_principal' => $principal !== null ? trim(sprintf('%s %s', $principal['clave'] ?? '', $principal['nombre'])) : null,
                'rotacion_temprana' => $this->rotacionTemprana($bajasSucursal)['porcentaje'],
                'antiguedad_promedio_dias' => $this->antiguedadPromedio($bajasSucursal),
                'calidad_expediente' => $this->calidad($bajasSucursal)['porcentaje'],
                'bajas_lista' => array_values($bajasSucursal->map(fn (Baja $b): array => [
                    'id' => $b->id,
                    'folio' => $b->folio,
                    'colaborador' => $b->nombre_colaborador,
                    'fecha_baja' => $b->fecha_baja?->toDateString(),
                    'forma' => $b->forma_salida?->etiqueta(),
                    'causal' => $b->causal?->etiquetaConClave(),
                    'estatus' => $b->estatus->etiqueta(),
                ])->all()),
            ];
        }

        usort($filas, fn (array $a, array $b): int => [$a['es_corporativo'], $b['promedio_mensual'] ?? -1] <=> [$b['es_corporativo'], $a['promedio_mensual'] ?? -1]);

        return $filas;
    }

    /**
     * Bajas y plantilla promedio de un rango (para la tabla por sucursal).
     *
     * @param  Collection<int, int>  $sucursales
     * @param  array<string, mixed>  $filtros
     * @return array{bajas: int, plantilla_promedio: float|null, indice: float|null}
     */
    private function acumuladoRango(CarbonImmutable $desde, CarbonImmutable $hasta, Collection $sucursales, array $filtros): array
    {
        $bajas = 0;
        $plantillas = [];

        for ($m = $desde; $m <= $hasta; $m = $m->addMonth()) {
            $fila = $this->mes($m, $sucursales, $filtros);
            $bajas += (int) $fila['bajas'];

            if ($fila['plantilla_promedio'] !== null) {
                $plantillas[] = $fila['plantilla_promedio'];
            }
        }

        $promedio = $plantillas === [] ? null : round(array_sum($plantillas) / count($plantillas), 2);

        return ['bajas' => $bajas, 'plantilla_promedio' => $promedio, 'indice' => $this->indice($bajas, $promedio)];
    }

    /**
     * Rotación por puesto (bajas del sistema; plantilla reconstruida por
     * fechas para el puesto).
     *
     * @param  Collection<int, Baja>  $bajas
     * @param  Collection<int, int>  $sucursales
     * @return list<array<string, mixed>>
     */
    private function porPuesto(Collection $bajas, CarbonImmutable $desde, CarbonImmutable $hasta, Collection $sucursales): array
    {
        $filas = [];

        foreach ($bajas->groupBy(fn (Baja $b): string => (string) ($b->puesto_id ?? 'sin')) as $clave => $grupo) {
            $primera = $grupo->first();
            $plantillas = [];

            if ($clave !== 'sin') {
                for ($m = $desde->max($this->mesInicioSistema()); $m <= $hasta; $m = $m->addMonth()) {
                    $p = $this->plantillaPuesto($m, $sucursales, (int) $clave);

                    if ($p !== null) {
                        $plantillas[] = $p;
                    }
                }
            }

            $promedio = $plantillas === [] ? null : round(array_sum($plantillas) / count($plantillas), 2);

            $filas[] = [
                'puesto' => $primera instanceof Baja ? ($primera->puesto->nombre ?? 'Sin puesto') : 'Sin puesto',
                'bajas' => $grupo->count(),
                'plantilla_promedio' => $promedio,
                'indice' => $this->indice($grupo->count(), $promedio),
                'rotacion_temprana' => $this->rotacionTemprana($grupo)['porcentaje'],
                'antiguedad_promedio_dias' => $this->antiguedadPromedio($grupo),
            ];
        }

        usort($filas, fn (array $a, array $b): int => $b['bajas'] <=> $a['bajas']);

        return $filas;
    }

    /**
     * Plantilla promedio de un puesto en un mes (activos el día 1 y el
     * último día, reconstruidos por fechas de ingreso/baja).
     *
     * @param  Collection<int, int>  $sucursales
     */
    private function plantillaPuesto(CarbonImmutable $mes, Collection $sucursales, int $puestoId): ?float
    {
        $inicio = $mes->startOfMonth();
        $cierre = $mes->endOfMonth()->startOfDay()->min(CarbonImmutable::today());
        $contar = fn (CarbonImmutable $fecha): int => Colaborador::query()->withTrashed()
            ->where('puesto_id', $puestoId)
            ->whereIn('sucursal_principal_id', $sucursales->all())
            ->whereNotNull('fecha_ingreso')
            ->whereDate('fecha_ingreso', '<=', $fecha->toDateString())
            ->where(fn (Builder $q) => $q->where(fn (Builder $s) => $s->whereNull('fecha_baja')->where('estatus', '!=', EstadoUsuario::Inactivo->value))->orWhereDate('fecha_baja', '>=', $fecha->toDateString()))
            ->count();

        if ($inicio->isAfter(CarbonImmutable::today())) {
            return null;
        }

        $promedio = ($contar($inicio) + $contar($cierre)) / 2;

        return $promedio > 0 ? round($promedio, 2) : null;
    }

    /**
     * @param  Collection<int, int>  $sucursales
     * @return array<string, mixed>
     */
    private function opciones(Collection $sucursales): array
    {
        return [
            'sucursales' => array_values(Sucursal::query()->whereIn('id', $sucursales->all())->orderBy('nombre')->get(['id', 'nombre'])->map(fn (Sucursal $s): array => ['id' => $s->id, 'nombre' => $s->nombre])->all()),
            'puestos' => array_values(Puesto::query()->orderBy('nombre')->get(['id', 'nombre'])->map(fn (Puesto $p): array => ['id' => $p->id, 'nombre' => $p->nombre])->all()),
            'formas' => array_map(fn (FormaSalida $f): array => ['value' => $f->value, 'label' => $f->etiqueta(), 'token' => $f->token()], FormaSalida::cases()),
            'causales' => array_values(CausalRotacion::query()->delCatalogo()->ordenadas()->get()->map(fn (CausalRotacion $c): array => ['id' => $c->id, 'clave' => $c->clave, 'nombre' => $c->nombre, 'forma' => $c->forma()->value])->all()),
        ];
    }
}
