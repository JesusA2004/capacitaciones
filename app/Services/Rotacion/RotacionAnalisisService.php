<?php

namespace App\Services\Rotacion;

use App\Enums\ClasificacionSalida;
use App\Enums\RangoAntiguedad;
use App\Models\Baja;
use App\Models\BajaHistorica;
use App\Models\CausalAnalitica;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\RotacionPeriodo;
use App\Models\Sucursal;
use App\Services\Bajas\TableroRotacionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Secciones del módulo de Rotación (resumen, tendencia, causales, antigüedad,
 * sucursales, matriz y calidad del dato) sobre los eventos de
 * RotacionMotorService y la plantilla de TableroRotacionService. Pantalla y
 * exportaciones llaman a este mismo servicio con los mismos filtros: nunca dan
 * cifras distintas.
 *
 * Definiciones (docs/ROTACION.md):
 *  - Participación = bajas del grupo ÷ bajas del alcance × 100.
 *  - Índice mensual = bajas del mes ÷ plantilla promedio del mes × 100
 *    (FormulaRotacion). Sin plantilla: null, nunca 0.
 *  - Rotación del periodo = bajas ÷ promedio de las plantillas promedio
 *    mensuales × 100 (la misma definición de siempre).
 *  - Promedio mensual de bajas = bajas ÷ meses considerados (eventos, no %).
 *  - Promedio de índices = promedio de los índices mensuales con dato.
 *  - Rotación temprana = bajas antes de 6 meses ÷ bajas con fecha de ingreso
 *    válida (las demás se excluyen del denominador y se informan).
 */
class RotacionAnalisisService
{
    /** @var array<string, float|null> */
    private array $plantillas = [];

    public function __construct(
        private readonly RotacionMotorService $motor,
        private readonly TableroRotacionService $tablero,
        private readonly RotacionMetaService $metas,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resumen(FiltrosRotacion $f): array
    {
        $conteo = $this->conteo($f);
        $meses = $this->mesesConsiderados($f);
        $eventos = $this->motor->eventos($f);
        $temprana = $this->temprana($eventos);
        $rotacion = $this->rotacionPeriodo($f, $conteo['total']);
        $anterior = $this->periodoAnterior($f);
        $conteoAnterior = $anterior !== null ? $this->conteo($anterior) : null;
        $rotacionAnterior = $anterior !== null && $conteoAnterior !== null && $this->mesesConsiderados($anterior) > 0
            ? $this->rotacionPeriodo($anterior, $conteoAnterior['total'])
            : null;
        $pct = fn (int $n): ?float => $conteo['total'] > 0 ? round($n * 100 / $conteo['total'], 1) : null;

        return [
            'total' => $conteo['total'],
            'meses' => $meses,
            'promedio_mensual' => $meses > 0 ? round($conteo['total'] / $meses, 1) : null,
            'voluntarias' => ['bajas' => $conteo['voluntarias'], 'porcentaje' => $pct($conteo['voluntarias'])],
            'involuntarias' => ['bajas' => $conteo['involuntarias'], 'porcentaje' => $pct($conteo['involuntarias'])],
            'sin_clasificar' => [
                'bajas' => $conteo['sin_clasificar'],
                'porcentaje' => $pct($conteo['sin_clasificar']),
                // De las sin clasificar: cuántas son del Excel sin desglose
                // (no se pueden revisar una por una).
                'sin_desglose' => $conteo['sin_desglose'],
                'por_revisar' => $conteo['sin_clasificar'] - min($conteo['sin_clasificar'], $conteo['sin_desglose']),
            ],
            'no_desglosables' => $conteo['no_desglosables'],
            'temprana' => $temprana,
            'rotacion' => $rotacion,
            'comparativo' => $anterior !== null && $conteoAnterior !== null && $this->mesesConsiderados($anterior) > 0 ? [
                'desde' => $anterior->desde->toDateString(),
                'hasta' => $anterior->hasta->toDateString(),
                'total' => $conteoAnterior['total'],
                'delta_total' => $conteo['total'] - $conteoAnterior['total'],
                'indice' => $rotacionAnterior['indice'] ?? null,
                'delta_indice' => $rotacion['indice'] !== null && ($rotacionAnterior['indice'] ?? null) !== null ? round($rotacion['indice'] - $rotacionAnterior['indice'], 2) : null,
            ] : null,
            'estructurales' => $eventos->filter(fn (array $e): bool => $e['causal_analitica_id'] !== null && ($this->motor->analiticas()->get($e['causal_analitica_id'])?->es_estructural ?? false))->count(),
            'identidad' => $conteo['voluntarias'] + $conteo['involuntarias'] + $conteo['sin_clasificar'] === $conteo['total'],
        ];
    }

    /**
     * Un punto por mes del rango (nunca meses futuros), con su desglose, su
     * índice y de qué fuente salió. Marca el mes con más bajas y el corte
     * parcial.
     *
     * @return array{meses: list<array<string, mixed>>, promedio: float|null, mes_mayor: string|null}
     */
    public function tendencia(FiltrosRotacion $f): array
    {
        $celdas = $this->motor->celdas($f)->groupBy('mes');
        $eventos = $this->motor->eventos($f)->groupBy('mes');
        $hoy = CarbonImmutable::today();
        $meses = [];

        for ($m = $f->desde->startOfMonth(); $m->lte($f->hasta); $m = $m->addMonth()) {
            $clave = $m->format('Y-m');
            /** @var Collection<int, array<string, mixed>> $delMes */
            $delMes = $celdas->get($clave, collect());
            $sinDatos = $delMes->isEmpty() && $m->lt($this->motor->mesInicioSistema());

            if ($f->conDetalle()) {
                /** @var Collection<int, array<string, mixed>> $ev */
                $ev = $eventos->get($clave, collect());
                $fila = [
                    'total' => $ev->count(),
                    'voluntarias' => $ev->where('clasificacion', ClasificacionSalida::Voluntaria)->count(),
                    'involuntarias' => $ev->where('clasificacion', ClasificacionSalida::Involuntaria)->count(),
                    'sin_clasificar' => $ev->where('clasificacion', ClasificacionSalida::SinClasificar)->count(),
                    'no_desglosables' => (int) $delMes->sum('sin_desglose'),
                ];
            } else {
                $fila = [
                    'total' => (int) $delMes->sum('total'),
                    'voluntarias' => (int) $delMes->sum('voluntarias'),
                    'involuntarias' => (int) $delMes->sum('involuntarias'),
                    'sin_clasificar' => (int) $delMes->sum('sin_clasificar'),
                    'no_desglosables' => 0,
                ];
            }

            $plantilla = $f->conDetalle() ? null : $this->plantilla($m, $f->sucursales);

            $meses[] = [
                'periodo' => $clave,
                'anio' => $m->year,
                'mes' => $m->month,
                ...$fila,
                'sin_datos' => $sinDatos,
                'sin_desglose' => (int) $delMes->sum('sin_desglose'),
                'plantilla_promedio' => $plantilla,
                'indice' => $sinDatos ? null : FormulaRotacion::indice($fila['total'], $plantilla),
                'en_curso' => $m->isSameMonth($hoy),
                'corte_parcial' => $m->isSameMonth($f->hasta) && ! $f->hasta->isSameDay($m->endOfMonth()),
                'discrepancias' => $delMes->where('discrepancia', true)->count(),
                'fuentes' => $this->fuentesDe($delMes),
            ];
        }

        $conDatos = array_values(array_filter($meses, fn (array $m): bool => ! $m['sin_datos']));
        $mayor = null;

        foreach ($conDatos as $m) {
            if ($m['total'] > 0 && ($mayor === null || $m['total'] > $mayor['total'])) {
                $mayor = $m;
            }
        }

        return [
            'meses' => $meses,
            'promedio' => $conDatos === [] ? null : round(array_sum(array_column($conDatos, 'total')) / count($conDatos), 1),
            'mes_mayor' => $mayor['periodo'] ?? null,
        ];
    }

    /**
     * Causales homologadas, de mayor a menor. Las bajas del Excel sin
     * desglose no tienen causal: se informan aparte, nunca como una causal.
     *
     * @return array{causales: list<array<string, mixed>>, base: int, sin_desglose: int}
     */
    public function causales(FiltrosRotacion $f): array
    {
        $eventos = $this->motor->eventos($f);
        $base = $eventos->count();
        $analiticas = $this->motor->analiticas();

        $causales = $eventos->groupBy(fn (array $e): string => $e['causal_analitica_id'] !== null ? 'a'.$e['causal_analitica_id'] : 'pendiente')
            ->map(function (Collection $grupo, string $clave) use ($analiticas, $base): array {
                $causal = $clave === 'pendiente' ? null : $analiticas->get((int) substr($clave, 1));

                return [
                    'id' => $causal?->id,
                    'clave' => $causal->clave ?? 'sin_homologar',
                    'nombre' => $causal->nombre ?? 'Sin homologar (causal del catálogo sin categoría)',
                    'clasificacion' => $causal?->clasificacion->value,
                    'clasificacion_etiqueta' => $causal?->clasificacion->etiqueta() ?? 'Según forma de salida',
                    'es_estructural' => $causal->es_estructural ?? false,
                    'bajas' => $grupo->count(),
                    'porcentaje' => $base > 0 ? round($grupo->count() * 100 / $base, 1) : null,
                    'sucursales' => $grupo->pluck('sucursal_id')->unique()->count(),
                ];
            })
            ->sortByDesc('bajas')
            ->values()
            ->all();

        return [
            'causales' => $causales,
            'base' => $base,
            'sin_desglose' => (int) $this->motor->celdas($f)->sum('sin_desglose'),
        ];
    }

    /**
     * Rangos de antigüedad (sin traslapes), promedio y mediana en días sobre
     * las bajas con fecha de ingreso válida.
     *
     * @return array<string, mixed>
     */
    public function antiguedad(FiltrosRotacion $f): array
    {
        $eventos = $this->motor->eventos($f);
        $validos = $eventos->filter(fn (array $e): bool => $e['rango'] !== RangoAntiguedad::SinFecha);
        $dias = $validos->pluck('antiguedad_dias')->filter(fn ($d): bool => $d !== null)->map(fn ($d): int => (int) $d)->sort()->values();
        $base = $validos->count();

        $rangos = array_map(function (RangoAntiguedad $r) use ($eventos, $base): array {
            $n = $eventos->where('rango', $r)->count();

            return [
                'clave' => $r->value,
                'etiqueta' => $r->etiqueta(),
                'bajas' => $n,
                'porcentaje' => $r === RangoAntiguedad::SinFecha || $base === 0 ? null : round($n * 100 / $base, 1),
                'temprana' => $r->esTemprana(),
            ];
        }, RangoAntiguedad::cases());

        $antes = fn (int $limiteDias): int => $dias->filter(fn (int $d): bool => $d < $limiteDias)->count();

        return [
            'rangos' => $rangos,
            'base' => $base,
            'excluidas' => $eventos->count() - $base,
            'inconsistentes' => $eventos->where('fecha_inconsistente', true)->count(),
            'antes_90_dias' => $antes(90),
            'antes_6_meses' => $validos->filter(fn (array $e): bool => $e['rango']->esTemprana())->count(),
            'primer_anio' => $validos->filter(fn (array $e): bool => in_array($e['rango'], [RangoAntiguedad::Menos3Meses, RangoAntiguedad::De3A6Meses, RangoAntiguedad::De6A12Meses], true))->count(),
            'promedio_dias' => $dias->isEmpty() ? null : (int) round((float) $dias->avg()),
            'mediana_dias' => $this->mediana($dias),
            'sin_desglose' => (int) $this->motor->celdas($f)->sum('sin_desglose'),
        ];
    }

    /**
     * Ranking por sucursal: bajas, participación, índice con SU plantilla,
     * desglose, causal principal, rotación temprana y su serie mensual.
     *
     * @return list<array<string, mixed>>
     */
    public function sucursales(FiltrosRotacion $f): array
    {
        $celdas = $this->motor->celdas($f)->groupBy('sucursal_id');
        $eventos = $this->motor->eventos($f)->groupBy('sucursal_id');
        $total = $this->conteo($f)['total'];
        $nombres = Sucursal::query()->whereIn('id', $f->sucursales->all())->get(['id', 'nombre', 'empresa_id', 'es_corporativo'])->keyBy('id');
        $empresas = Empresa::query()->pluck('nombre', 'id');
        $analiticas = $this->motor->analiticas();
        $filas = [];

        foreach ($f->sucursales as $id) {
            /** @var Collection<int, array<string, mixed>> $c */
            $c = $celdas->get($id, collect());
            /** @var Collection<int, array<string, mixed>> $ev */
            $ev = $eventos->get($id, collect());
            $bajas = $f->conDetalle() ? $ev->count() : (int) $c->sum('total');

            if ($bajas === 0) {
                continue;
            }

            $una = $f->conSucursales(collect([$id]));
            $principal = $ev->groupBy('causal_analitica_id')->sortByDesc(fn (Collection $g): int => $g->count())->keys()->first();
            $rotacion = $f->conDetalle() ? ['indice' => null, 'plantilla_promedio' => null, 'promedio_indices' => null] : $this->rotacionPeriodo($una, $bajas);
            $temprana = $this->temprana($ev);
            $sucursal = $nombres->get($id);
            $serie = [];

            for ($m = $f->desde->startOfMonth(); $m->lte($f->hasta); $m = $m->addMonth()) {
                $clave = $m->format('Y-m');
                $serie[] = $f->conDetalle() ? $ev->where('mes', $clave)->count() : (int) $c->where('mes', $clave)->sum('total');
            }

            $filas[] = [
                'sucursal_id' => $id,
                'sucursal' => $sucursal->nombre ?? sprintf('Sucursal #%d', $id),
                'empresa' => $sucursal?->empresa_id !== null ? ($empresas[$sucursal->empresa_id] ?? null) : null,
                'es_corporativo' => (bool) ($sucursal->es_corporativo ?? false),
                'bajas' => $bajas,
                'participacion' => $total > 0 ? round($bajas * 100 / $total, 1) : null,
                'plantilla_promedio' => $rotacion['plantilla_promedio'],
                'indice' => $rotacion['indice'],
                // Semáforo del promedio mensual contra la meta de su segmento
                // (Corporativo tiene la suya).
                'promedio_indices' => $rotacion['promedio_indices'],
                'semaforo' => $this->metas->semaforo($this->tablero->segmentoDe(collect([$id])), $rotacion['promedio_indices'], $f->hasta->startOfMonth()),
                'voluntarias' => $f->conDetalle() ? $ev->where('clasificacion', ClasificacionSalida::Voluntaria)->count() : (int) $c->sum('voluntarias'),
                'involuntarias' => $f->conDetalle() ? $ev->where('clasificacion', ClasificacionSalida::Involuntaria)->count() : (int) $c->sum('involuntarias'),
                'sin_clasificar' => $f->conDetalle() ? $ev->where('clasificacion', ClasificacionSalida::SinClasificar)->count() : (int) $c->sum('sin_clasificar'),
                'sin_desglose' => (int) $c->sum('sin_desglose'),
                'causal_principal' => $principal !== null && $principal !== '' ? ($analiticas->get((int) $principal)->nombre ?? null) : null,
                'temprana' => $temprana['porcentaje'],
                'serie' => $serie,
            ];
        }

        usort($filas, fn (array $a, array $b): int => $b['bajas'] <=> $a['bajas'] ?: strcmp($a['sucursal'], $b['sucursal']));

        return $filas;
    }

    /**
     * Mapa de calor sucursal × causal homologada, solo con bajas individuales.
     *
     * @return array{filas: list<array<string, mixed>>, columnas: list<array<string, mixed>>, total: int, maximo: int, sin_desglose: int}
     */
    public function matriz(FiltrosRotacion $f): array
    {
        $eventos = $this->motor->eventos($f);
        $analiticas = $this->motor->analiticas();
        $nombres = Sucursal::query()->whereIn('id', $eventos->pluck('sucursal_id')->unique()->all())->pluck('nombre', 'id');
        $claveCausal = fn (array $e): string => $e['causal_analitica_id'] !== null ? (string) $e['causal_analitica_id'] : 'pendiente';

        $columnas = $eventos->groupBy($claveCausal)
            ->map(fn (Collection $g, string $clave): array => [
                'clave' => $clave,
                'causal_analitica_id' => $clave === 'pendiente' ? null : (int) $clave,
                'nombre' => $clave === 'pendiente' ? 'Sin homologar' : ($analiticas->get((int) $clave)->nombre ?? 'Sin homologar'),
                'total' => $g->count(),
            ])
            ->sortByDesc('total')
            ->values();

        $maximo = 0;
        $filas = $eventos->groupBy('sucursal_id')
            ->map(function (Collection $g, int|string $sucursalId) use ($columnas, $claveCausal, $nombres, &$maximo): array {
                $celdas = [];

                foreach ($columnas as $col) {
                    $n = $g->filter(fn (array $e): bool => $claveCausal($e) === $col['clave'])->count();
                    $celdas[$col['clave']] = $n;
                    $maximo = max($maximo, $n);
                }

                return [
                    'sucursal_id' => (int) $sucursalId,
                    'sucursal' => (string) ($nombres[$sucursalId] ?? sprintf('Sucursal #%d', $sucursalId)),
                    'celdas' => $celdas,
                    'total' => $g->count(),
                ];
            })
            ->sortByDesc('total')
            ->values();

        return [
            'filas' => $filas->all(),
            'columnas' => $columnas->all(),
            'total' => $eventos->count(),
            'maximo' => $maximo,
            'sin_desglose' => (int) $this->motor->celdas($f)->sum('sin_desglose'),
        ];
    }

    /**
     * Pendientes de calidad del dato y descuadres entre fuentes. Se calculan
     * sin filtros de detalle: son del periodo y alcance completos.
     *
     * @return array<string, mixed>
     */
    public function calidad(FiltrosRotacion $f): array
    {
        $base = $f->sinDetalle();
        $eventos = $this->motor->eventos($base);
        $sinCausal = $this->motor->idSinCausal();
        $nombres = Sucursal::query()->whereIn('id', $base->sucursales->all())->pluck('nombre', 'id');

        $discrepancias = $this->motor->celdas($base)->where('discrepancia', true)->values()
            ->map(fn (array $c): array => [
                'mes' => $c['mes'],
                'sucursal_id' => $c['sucursal_id'],
                'sucursal' => (string) ($nombres[$c['sucursal_id']] ?? ''),
                'agregado' => $c['agregado'],
                'individuales' => $c['individuales'],
                'diferencia' => (int) $c['agregado'] - $c['individuales'],
            ])->all();

        return [
            'sin_causal' => $eventos->where('causal_analitica_id', $sinCausal)->count(),
            'sin_homologar' => $eventos->where('homologada', false)->count(),
            'sin_fecha_ingreso' => $eventos->filter(fn (array $e): bool => $e['fecha_ingreso'] === null)->count(),
            'fecha_inconsistente' => $eventos->where('fecha_inconsistente', true)->count(),
            'posibles_duplicados' => $this->posiblesDuplicados($base)->count(),
            'duplicadas_ligadas' => BajaHistorica::query()->where('estado', BajaHistorica::DUPLICADA)->whereIn('sucursal_id', $base->sucursales->all())->whereBetween('fecha_baja', [$base->desde->toDateString(), $base->hasta->toDateString()])->count(),
            'discrepancias' => $discrepancias,
            'meses_recortados' => $this->motor->agregadosRecortados($base),
        ];
    }

    /**
     * Pares (histórica válida, baja del sistema) que parecen el mismo evento:
     * misma persona (colaborador o nombre normalizado) con menos de 8 días de
     * diferencia en la fecha de baja. Se revisan a mano: nunca se ligan solos.
     *
     * @return Collection<int, array{historica_id: int, baja_id: int, nombre: string, fecha_historica: string, fecha_sistema: string}>
     */
    public function posiblesDuplicados(FiltrosRotacion $f): Collection
    {
        $base = $f->sinDetalle();
        $eventos = $this->motor->eventos(new FiltrosRotacion($base->periodo, $base->anio, $base->mes, $base->desde->subDays(7), $base->hasta, $base->sucursales, $base->empresaId, $base->sucursalId, null, null, null, null, null, 'todas', $base->corteParcial), false);
        $sistema = $eventos->where('fuente', 'sistema');
        $pares = collect();

        foreach ($eventos->where('fuente', 'historico') as $h) {
            $igual = $sistema->first(fn (array $s): bool => (($h['colaborador_id'] !== null && $h['colaborador_id'] === $s['colaborador_id'])
                    || BajaHistorica::normalizar($h['nombre']) === BajaHistorica::normalizar($s['nombre']))
                && abs($h['fecha_baja']->diffInDays($s['fecha_baja'])) <= 7);

            if ($igual !== null && $h['fecha_baja']->gte($base->desde)) {
                $pares->push([
                    'historica_id' => $h['id'],
                    'baja_id' => $igual['id'],
                    'nombre' => $h['nombre'],
                    'fecha_historica' => $h['fecha_baja']->toDateString(),
                    'fecha_sistema' => $igual['fecha_baja']->toDateString(),
                ]);
            }
        }

        return $pares;
    }

    /**
     * Opciones de los filtros dentro del alcance.
     *
     * @param  Collection<int, int>  $scope
     * @return array<string, mixed>
     */
    public function opciones(Collection $scope): array
    {
        $sucursales = Sucursal::query()->whereIn('id', $scope->all())->orderBy('nombre')->get(['id', 'nombre', 'empresa_id']);

        return [
            'empresas' => array_values(Empresa::query()->whereIn('id', $sucursales->pluck('empresa_id')->filter()->unique()->all())->orderBy('nombre')->get(['id', 'nombre'])->map(fn (Empresa $e): array => ['id' => $e->id, 'nombre' => $e->nombre])->all()),
            'sucursales' => array_values($sucursales->map(fn (Sucursal $s): array => ['id' => $s->id, 'nombre' => $s->nombre, 'empresa_id' => $s->empresa_id])->all()),
            'departamentos' => array_values(Departamento::query()->orderBy('nombre')->get(['id', 'nombre'])->map(fn (Departamento $d): array => ['id' => $d->id, 'nombre' => $d->nombre])->all()),
            'puestos' => array_values(Puesto::query()->orderBy('nombre')->get(['id', 'nombre'])->map(fn (Puesto $p): array => ['id' => $p->id, 'nombre' => $p->nombre])->all()),
            'clasificaciones' => array_map(fn (ClasificacionSalida $c): array => ['value' => $c->value, 'label' => $c->etiqueta()], ClasificacionSalida::cases()),
            'causales' => array_values($this->motor->analiticas()->map(fn (CausalAnalitica $c): array => ['id' => $c->id, 'nombre' => $c->nombre, 'clasificacion' => $c->clasificacion->value, 'activo' => $c->activo])->all()),
            'antiguedades' => array_map(fn (RangoAntiguedad $r): array => ['value' => $r->value, 'label' => $r->etiqueta()], RangoAntiguedad::cases()),
            'anios' => $this->aniosConDatos(),
        ];
    }

    /**
     * Totales del alcance: con filtros de detalle, solo bajas individuales que
     * cumplen el filtro (y cuántas del Excel no se pudieron desglosar).
     *
     * @return array{total: int, voluntarias: int, involuntarias: int, sin_clasificar: int, sin_desglose: int, no_desglosables: int}
     */
    public function conteo(FiltrosRotacion $f): array
    {
        $celdas = $this->motor->celdas($f);

        if ($f->conDetalle()) {
            $ev = $this->motor->eventos($f);

            return [
                'total' => $ev->count(),
                'voluntarias' => $ev->where('clasificacion', ClasificacionSalida::Voluntaria)->count(),
                'involuntarias' => $ev->where('clasificacion', ClasificacionSalida::Involuntaria)->count(),
                'sin_clasificar' => $ev->where('clasificacion', ClasificacionSalida::SinClasificar)->count(),
                'sin_desglose' => 0,
                'no_desglosables' => (int) $celdas->sum('sin_desglose'),
            ];
        }

        return [
            'total' => (int) $celdas->sum('total'),
            'voluntarias' => (int) $celdas->sum('voluntarias'),
            'involuntarias' => (int) $celdas->sum('involuntarias'),
            'sin_clasificar' => (int) $celdas->sum('sin_clasificar'),
            'sin_desglose' => (int) $celdas->sum('sin_desglose'),
            'no_desglosables' => 0,
        ];
    }

    /**
     * Meses del rango que tienen información (bajas, total del Excel o que ya
     * son del sistema). Un mes sin ninguna fuente no se cuenta como «0 bajas».
     */
    public function mesesConsiderados(FiltrosRotacion $f): int
    {
        $conCeldas = $this->motor->celdas($f->sinDetalle())->pluck('mes')->unique();
        $inicioSistema = $this->motor->mesInicioSistema();
        $n = 0;

        for ($m = $f->desde->startOfMonth(); $m->lte($f->hasta); $m = $m->addMonth()) {
            if ($conCeldas->contains($m->format('Y-m')) || $m->gte($inicioSistema)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $eventos
     * @return array{bajas: int, base: int, excluidas: int, porcentaje: float|null}
     */
    private function temprana(Collection $eventos): array
    {
        $validos = $eventos->filter(fn (array $e): bool => $e['rango'] !== RangoAntiguedad::SinFecha);
        $tempranas = $validos->filter(fn (array $e): bool => $e['rango']->esTemprana())->count();

        return [
            'bajas' => $tempranas,
            'base' => $validos->count(),
            'excluidas' => $eventos->count() - $validos->count(),
            'porcentaje' => $validos->isNotEmpty() ? round($tempranas * 100 / $validos->count(), 1) : null,
        ];
    }

    /**
     * Rotación del periodo con la fórmula de siempre y el promedio de los
     * índices mensuales. Con filtros de detalle no aplica (no hay plantilla
     * por causal ni por antigüedad).
     *
     * @return array{indice: float|null, plantilla_promedio: float|null, promedio_indices: float|null, meses_con_plantilla: int}
     */
    private function rotacionPeriodo(FiltrosRotacion $f, int $bajas): array
    {
        if ($f->conDetalle()) {
            return ['indice' => null, 'plantilla_promedio' => null, 'promedio_indices' => null, 'meses_con_plantilla' => 0];
        }

        $celdas = $this->motor->celdas($f)->groupBy('mes');
        $plantillas = [];
        $indices = [];
        $cerrados = [];

        for ($m = $f->desde->startOfMonth(); $m->lte($f->hasta); $m = $m->addMonth()) {
            $p = $this->plantilla($m, $f->sucursales);

            if ($p === null) {
                continue;
            }

            $plantillas[] = $p;
            $indice = FormulaRotacion::indice((int) $celdas->get($m->format('Y-m'), collect())->sum('total'), $p);

            if ($indice !== null) {
                $indices[] = $indice;

                if (! $m->isSameMonth(CarbonImmutable::today())) {
                    $cerrados[] = $indice;
                }
            }
        }

        $promedio = $plantillas === [] ? null : round(array_sum($plantillas) / count($plantillas), 2);
        // El mes en curso todavía puede cambiar: si hay meses cerrados, no entra.
        $base = $cerrados !== [] ? $cerrados : $indices;

        return [
            'indice' => FormulaRotacion::indice($bajas, $promedio),
            'plantilla_promedio' => $promedio,
            'promedio_indices' => $base === [] ? null : round(array_sum($base) / count($base), 2),
            'meses_con_plantilla' => count($plantillas),
        ];
    }

    /**
     * Periodo comparable anterior: el mes previo, o el mismo rango un año
     * antes para el acumulado y el año; un rango libre se compara con el de
     * igual duración inmediatamente anterior.
     */
    private function periodoAnterior(FiltrosRotacion $f): ?FiltrosRotacion
    {
        return match ($f->periodo) {
            'mes' => $f->conRango($f->desde->subMonthNoOverflow()->startOfMonth(), $f->desde->subMonthNoOverflow()->endOfMonth()->startOfDay()),
            'acumulado', 'anio' => $f->conRango($f->desde->subYear(), $f->hasta->subYear()),
            default => $f->conRango($f->desde->subDays((int) $f->desde->diffInDays($f->hasta) + 1), $f->desde->subDay()),
        };
    }

    /**
     * @param  Collection<int, int>  $sucursales
     */
    private function plantilla(CarbonImmutable $mes, Collection $sucursales): ?float
    {
        $clave = $mes->format('Y-m').'|'.$sucursales->sort()->implode(',');

        if (! array_key_exists($clave, $this->plantillas)) {
            $this->plantillas[$clave] = $mes->startOfMonth()->isAfter(CarbonImmutable::today()) ? null : $this->tablero->plantillaMes($mes->startOfMonth(), $sucursales);
        }

        return $this->plantillas[$clave];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $celdas
     * @return list<string>
     */
    private function fuentesDe(Collection $celdas): array
    {
        $fuentes = [];

        if ($celdas->sum('individuales') > 0) {
            $fuentes[] = 'individual';
        }

        if ($celdas->sum('sin_desglose') > 0) {
            $fuentes[] = 'agregado';
        }

        return $fuentes;
    }

    /**
     * @param  Collection<int, int>  $valores  Ordenados.
     */
    private function mediana(Collection $valores): ?int
    {
        $n = $valores->count();

        if ($n === 0) {
            return null;
        }

        $medio = intdiv($n, 2);

        return $n % 2 === 1 ? (int) $valores[$medio] : (int) round(((int) $valores[$medio - 1] + (int) $valores[$medio]) / 2);
    }

    /**
     * @return list<int>
     */
    private function aniosConDatos(): array
    {
        $anios = collect([CarbonImmutable::today()->year])
            ->merge(BajaHistorica::query()->selectRaw('distinct substr(fecha_baja, 1, 4) as anio')->pluck('anio')->map(fn ($a): int => (int) $a))
            ->merge(RotacionPeriodo::query()->distinct()->pluck('anio')->map(fn ($a): int => (int) $a))
            ->merge(Baja::query()->whereNotNull('fecha_baja')->selectRaw('distinct substr(fecha_baja, 1, 4) as anio')->pluck('anio')->map(fn ($a): int => (int) $a));

        return array_values($anios->filter(fn (int $a): bool => $a > 2000)->unique()->sortDesc()->values()->all());
    }
}
