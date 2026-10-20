<?php

namespace App\Services\Bajas;

use App\Enums\EstadoUsuario;
use App\Enums\EstatusBaja;
use App\Enums\FormaSalida;
use App\Models\Baja;
use App\Models\CausalRotacion;
use App\Models\Colaborador;
use App\Models\PlantillaMensual;
use App\Models\Puesto;
use App\Models\RotacionPeriodo;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Rotacion\RotacionExcelLector;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Tablero de rotación (especificación de RH §9, docs/BAJAS_Y_CAUSALES.md).
 *
 * Fuentes y cómo se combinan:
 *  - Meses ANTERIORES al módulo (config bajas.tablero.mes_inicio_sistema):
 *    agregados mensuales del Excel de rotación ya importado
 *    (rotacion_periodos: bajas y plantilla inicial/final por sucursal).
 *  - Desde ese mes: bajas del SISTEMA (estatus Cerrada y es_rotacion = true;
 *    los movimientos internos no cuentan, RN-14) y la plantilla mensual
 *    (plantilla_mensual; si a un mes/sucursal le falta, se usa la del Excel).
 *  - El desglose por forma, causal, puesto, antigüedad y calidad del
 *    expediente SOLO existe para bajas del sistema: el Excel no lo trae.
 *
 * Índice = bajas ÷ plantilla promedio × 100, con plantilla promedio =
 * (inicio + cierre) / 2 (también para los meses del Excel). El acumulado
 * del año = bajas del año ÷ promedio de las plantillas promedio mensuales
 * × 100. Alcance: AlcanceOrganizacionalService (el gerente ve su sucursal;
 * RH y Dirección, todas).
 */
class TableroRotacionService
{
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
    private ?Collection $cerradas = null;

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly BajaDocumentosService $documentos,
    ) {}

    /**
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    public function tablero(User $usuario, array $entrada): array
    {
        $filtros = $this->filtros($usuario, $entrada);
        $sucursalesScope = $this->sucursalesVisibles($usuario);
        $sucursales = $filtros['sucursal_id'] !== null ? collect([$filtros['sucursal_id']]) : $sucursalesScope;
        $desde = CarbonImmutable::createFromFormat('!Y-m', $filtros['desde']) ?: CarbonImmutable::now()->startOfYear();
        $hasta = CarbonImmutable::createFromFormat('!Y-m', $filtros['hasta']) ?: CarbonImmutable::now()->startOfMonth();
        $inicioAnio = $hasta->startOfYear();

        $bajasRango = $this->bajasSistema($sucursales, $filtros, $desde, $hasta->endOfMonth());
        $mes = $this->mes($hasta, $sucursales, $filtros);
        $acumulado = $this->acumulado($inicioAnio, $hasta, $sucursales, $filtros);

        return [
            'filtros' => $filtros,
            'mes_inicio_sistema' => $this->mesInicioSistema()->format('Y-m'),
            'resumen' => [
                'mes' => $mes,
                'acumulado' => $acumulado,
                'por_forma' => $this->porForma($bajasRango, $this->plantillaPromedioRango($desde, $hasta, $sucursales, $filtros)),
                'rotacion_temprana' => $this->rotacionTemprana($bajasRango),
                'antiguedad_promedio_dias' => $this->antiguedadPromedio($bajasRango),
                'antiguedad_por_forma' => $this->antiguedadPorForma($bajasRango),
                'calidad_expediente' => $this->calidad($bajasRango),
                'bajas_sistema' => $bajasRango->count(),
            ],
            'tendencia' => $this->tendencia($hasta, $sucursalesScope, $filtros),
            'causales' => $this->causales($bajasRango),
            'sucursales' => $this->porSucursal($sucursales, $filtros, $desde, $hasta, $bajasRango),
            'puestos' => $this->porPuesto($bajasRango, $desde, $hasta, $sucursales),
            'opciones' => $this->opciones($sucursalesScope),
        ];
    }

    /**
     * Tabla exportable (Excel/PDF) con los filtros aplicados: una fila por
     * sucursal + total.
     *
     * @param  array<string, mixed>  $entrada
     * @return array{titulo: string, columnas: list<string>, filas: list<list<string|int|float|null>>}
     */
    public function exportable(User $usuario, array $entrada): array
    {
        $datos = $this->tablero($usuario, $entrada);
        $f = $datos['filtros'];
        $resumen = $datos['resumen'];
        $columnas = ['Sucursal', 'Bajas', 'Plantilla promedio', 'Índice %', '% Renuncia', '% No renovación', '% Rescisión', 'Causal principal', 'Rotación temprana %', 'Antigüedad promedio (días)', 'Calidad del expediente %', 'Vs. mes anterior'];
        $filas = [];

        foreach ($datos['sucursales'] as $s) {
            $filas[] = [
                $s['sucursal'], $s['bajas'], $s['plantilla_promedio'], $s['indice'],
                $s['por_forma']['RV'] ?? null, $s['por_forma']['NR'] ?? null, $s['por_forma']['RE'] ?? null,
                $s['causal_principal'], $s['rotacion_temprana'], $s['antiguedad_promedio_dias'], $s['calidad_expediente'],
                match ($s['tendencia']) {
                    'sube' => 'Sube',
                    'baja' => 'Baja',
                    'igual' => 'Igual',
                    default => '—',
                },
            ];
        }

        $filas[] = [
            'Total', $resumen['acumulado']['bajas'], $resumen['acumulado']['plantilla_promedio'], $resumen['acumulado']['indice'],
            null, null, null, $datos['causales'][0]['nombre'] ?? null, $resumen['rotacion_temprana']['porcentaje'], $resumen['antiguedad_promedio_dias'], $resumen['calidad_expediente']['porcentaje'], null,
        ];

        $etiquetas = array_filter([
            sprintf('%s a %s', $f['desde'], $f['hasta']),
            $f['sucursal_id'] !== null ? Sucursal::query()->where('id', $f['sucursal_id'])->value('nombre') : null,
            $f['forma'] !== null ? FormaSalida::tryFrom($f['forma'])?->etiqueta() : null,
        ]);

        return [
            'titulo' => sprintf('Rotación de personal · %s', implode(' · ', $etiquetas)),
            'columnas' => $columnas,
            'filas' => $filas,
        ];
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return array{desde: string, hasta: string, sucursal_id: int|null, puesto_id: int|null, forma: string|null, causal_id: int|null}
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

        return [
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
     * @return array{periodo: string, etiqueta: string, bajas: int|null, plantilla_promedio: float|null, indice: float|null, fuente: string}
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

            $plantilla = (float) $periodos->sum(fn (RotacionPeriodo $p): float => ($p->plantilla_inicial + $p->plantilla_final) / 2);

            // El Excel no trae forma, causal ni puesto: con esos filtros no hay cifra.
            return $conDesglose
                ? $this->filaMes($mes, null, $plantilla, 'excel_sin_desglose')
                : $this->filaMes($mes, (int) $periodos->sum('bajas'), $plantilla, 'excel');
        }

        $bajas = $this->bajasSistema($sucursales, $filtros, $mes, $mes->endOfMonth())->count();
        $plantilla = $filtros['puesto_id'] !== null
            ? $this->plantillaPuesto($mes, $sucursales, (int) $filtros['puesto_id'])
            : $this->plantillaMes($mes, $sucursales);

        return $this->filaMes($mes, $bajas, $plantilla, $plantilla === null ? 'sin_plantilla' : 'sistema');
    }

    /**
     * Plantilla promedio del mes (suma de las sucursales): plantilla_mensual,
     * y donde falte, la del Excel de rotación.
     *
     * @param  Collection<int, int>  $sucursales
     */
    public function plantillaMes(CarbonImmutable $mes, Collection $sucursales): ?float
    {
        $clave = $mes->format('Y-m');
        $this->fotosPorMes[$clave] ??= PlantillaMensual::query()->where('periodo', $clave)->whereNotNull('plantilla_promedio')->get();
        $fotos = $this->fotosPorMes[$clave]->whereIn('sucursal_id', $sucursales->all())->keyBy('sucursal_id');
        $excel = $this->periodosExcel($mes, $sucursales)->keyBy('sucursal_id');
        $total = 0.0;
        $alguna = false;

        foreach ($sucursales as $id) {
            if ($fotos->has($id)) {
                $total += (float) $fotos->get($id)?->plantilla_promedio;
                $alguna = true;
            } elseif ($excel->has($id)) {
                $p = $excel->get($id);
                $total += $p !== null ? ($p->plantilla_inicial + $p->plantilla_final) / 2 : 0;
                $alguna = true;
            }
        }

        return $alguna ? round($total, 2) : null;
    }

    /**
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

        // Todas las cerradas del sistema (desde el inicio del módulo) se cargan
        // una vez por petición y se filtran en memoria.
        $this->cerradas ??= Baja::query()
            ->with(['causal', 'sucursal:id,nombre', 'puesto:id,nombre', 'cierre', 'documentos'])
            ->where('estatus', EstatusBaja::Cerrada->value)
            ->where('es_rotacion', true)
            ->whereNotNull('fecha_baja')
            ->whereDate('fecha_baja', '>=', $inicio->toDateString())
            ->get();

        $ids = $sucursales->all();
        $desdeTexto = $desde->toDateString();
        $hastaTexto = $hasta->toDateString();

        return $this->cerradas
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
     * @return array{periodo: string, etiqueta: string, bajas: int|null, plantilla_promedio: float|null, indice: float|null, fuente: string}
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
        ];
    }

    private function indice(?int $bajas, ?float $plantilla): ?float
    {
        return $bajas !== null && $plantilla !== null && $plantilla > 0 ? round($bajas * 100 / $plantilla, 2) : null;
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
        $completas = $bajas->filter(fn (Baja $b): bool => $this->documentos->faltantes($b) === [])->count();

        return ['cerradas' => $bajas->count(), 'completas' => $completas, 'porcentaje' => $bajas->isNotEmpty() ? round($completas * 100 / $bajas->count(), 1) : null];
    }

    /**
     * Rotación mensual de los últimos 12 meses: global (todo el alcance) y,
     * si hay sucursal filtrada, también la de esa sucursal.
     *
     * @param  Collection<int, int>  $sucursalesScope
     * @param  array<string, mixed>  $filtros
     * @return list<array<string, mixed>>
     */
    private function tendencia(CarbonImmutable $hasta, Collection $sucursalesScope, array $filtros): array
    {
        $meses = (int) config('bajas.tablero.meses_tendencia', 12);
        $serie = [];

        for ($m = $hasta->subMonths($meses - 1); $m <= $hasta; $m = $m->addMonth()) {
            $global = $this->mes($m, $sucursalesScope, $filtros);
            $sucursal = $filtros['sucursal_id'] !== null ? $this->mes($m, collect([(int) $filtros['sucursal_id']]), $filtros) : null;

            $serie[] = [
                ...$global,
                'indice_sucursal' => $sucursal['indice'] ?? null,
                'bajas_sucursal' => $sucursal['bajas'] ?? null,
            ];
        }

        return $serie;
    }

    /**
     * Top de causales (número y % sobre el total del periodo, desc).
     *
     * @param  Collection<int, Baja>  $bajas
     * @return list<array{clave: string|null, nombre: string, forma: string|null, token: string|null, bajas: int, porcentaje: float}>
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
                    'bajas' => $grupo->count(),
                    'porcentaje' => round($grupo->count() * 100 / $total, 1),
                ];
            })
            ->sortByDesc('bajas')
            ->values()
            ->all());
    }

    /**
     * Tabla por sucursal con semáforo (sube / baja el índice del último mes
     * contra el mes anterior).
     *
     * @param  Collection<int, int>  $sucursales
     * @param  array<string, mixed>  $filtros
     * @param  Collection<int, Baja>  $bajasRango
     * @return list<array<string, mixed>>
     */
    private function porSucursal(Collection $sucursales, array $filtros, CarbonImmutable $desde, CarbonImmutable $hasta, Collection $bajasRango): array
    {
        $nombres = Sucursal::query()->whereIn('id', $sucursales->all())->pluck('nombre', 'id');
        $filas = [];

        foreach ($sucursales as $id) {
            $una = collect([$id]);
            $bajasSucursal = $bajasRango->where('sucursal_id', $id)->values();
            $actual = $this->mes($hasta, $una, $filtros);
            $anterior = $this->mes($hasta->subMonth(), $una, $filtros);
            $acumulado = $this->acumuladoRango($desde, $hasta, $una, $filtros);

            if ($acumulado['bajas'] === 0 && $acumulado['plantilla_promedio'] === null) {
                continue;
            }

            $total = $bajasSucursal->count();
            $porForma = [];

            foreach (FormaSalida::cases() as $forma) {
                $porForma[$forma->value] = $total > 0 ? round($bajasSucursal->filter(fn (Baja $b): bool => $b->forma_salida === $forma)->count() * 100 / $total, 1) : null;
            }

            $principal = $this->causales($bajasSucursal)[0] ?? null;

            $filas[] = [
                'sucursal_id' => $id,
                'sucursal' => (string) ($nombres[$id] ?? sprintf('Sucursal #%d', $id)),
                'bajas' => $acumulado['bajas'],
                'plantilla_promedio' => $acumulado['plantilla_promedio'],
                'indice' => $acumulado['indice'],
                'indice_mes' => $actual['indice'],
                'indice_mes_anterior' => $anterior['indice'],
                'tendencia' => $actual['indice'] === null || $anterior['indice'] === null ? null : ($actual['indice'] > $anterior['indice'] ? 'sube' : ($actual['indice'] < $anterior['indice'] ? 'baja' : 'igual')),
                'por_forma' => $porForma,
                'causal_principal' => $principal !== null ? trim(sprintf('%s %s', $principal['clave'] ?? '', $principal['nombre'])) : null,
                'rotacion_temprana' => $this->rotacionTemprana($bajasSucursal)['porcentaje'],
                'antiguedad_promedio_dias' => $this->antiguedadPromedio($bajasSucursal),
                'calidad_expediente' => $this->calidad($bajasSucursal)['porcentaje'],
            ];
        }

        usort($filas, fn (array $a, array $b): int => ($b['indice'] ?? -1) <=> ($a['indice'] ?? -1));

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
