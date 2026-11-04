<?php

namespace App\Services\Rotacion;

use App\Enums\ClasificacionSalida;
use App\Enums\EstatusBaja;
use App\Enums\FormaSalida;
use App\Enums\RangoAntiguedad;
use App\Models\Baja;
use App\Models\BajaHistorica;
use App\Models\CausalAnalitica;
use App\Models\CausalRotacion;
use App\Models\RotacionPeriodo;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Support\FechaMexico;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Fuente ÚNICA de los eventos de rotación (docs/ROTACION.md). Junta, sin contar
 * una baja dos veces:
 *
 *  1. Bajas del sistema (`bajas`): es_rotacion, en trámite o cerradas, por su
 *     último día laborado ya transcurrido. «No procede» y movimientos internos
 *     no cuentan.
 *  2. Bajas históricas individuales (`bajas_historicas`, estado «valida»): las
 *     ligadas a una baja del sistema quedan como duplicadas y no cuentan.
 *  3. Totales mensuales del Excel (`rotacion_periodos`), por sucursal.
 *
 * Por cada (mes, sucursal) — una «celda» — el total es el de las bajas
 * individuales; si el Excel trae un total MAYOR, la diferencia queda como «sin
 * desglose» (se cuenta, pero no tiene persona, causal ni antigüedad). Si las
 * individuales y el Excel no coinciden, la celda se marca como discrepancia:
 * nunca se oculta.
 *
 * Clasificación (voluntaria / involuntaria / sin clasificar) y causal
 * homologada: corrección de la baja → mapeo de su causal del catálogo →
 * «Sin homologar» clasificada por la forma de salida. Sin causal → «Sin causal
 * registrada» (sin clasificar). El motivo original nunca se toca.
 */
class RotacionMotorService
{
    /** @var array<string, Collection<int, array<string, mixed>>> */
    private array $eventosCache = [];

    /** @var Collection<int, CausalAnalitica>|null */
    private ?Collection $analiticas = null;

    /** @var array<int, array{analitica_id: int|null, forma: FormaSalida, nombre: string, clave: string|null}>|null */
    private ?array $catalogo = null;

    /** @var array<int, int|null>|null */
    private ?array $empresas = null;

    /** @var array<string, true>|null */
    private ?array $mesesConDetalle = null;

    /** @var array<int, array{nombre: string, grupo: string|null}>|null */
    private ?array $externas = null;

    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    /**
     * Sucursales que el usuario puede consultar en Rotación (las mismas para el
     * tablero, el histórico y las exportaciones).
     *
     * @return Collection<int, int>
     */
    public function sucursalesVisibles(User $usuario): Collection
    {
        if ($this->veTodo($usuario)) {
            return Sucursal::query()->pluck('id')->map(fn ($id): int => (int) $id)->values();
        }

        return $this->alcance->sucursalesVisiblesIds($usuario)->map(fn ($id): int => (int) $id)->values();
    }

    /**
     * Lee la query string y la acota: un año, un mes, el acumulado del año a
     * la fecha o un rango; nunca más allá de hoy. Una sucursal o empresa fuera
     * del alcance se ignora (no da error ni filtra datos ajenos).
     *
     * @param  array<string, mixed>  $entrada
     */
    public function filtros(User $usuario, array $entrada): FiltrosRotacion
    {
        $hoy = FechaMexico::inicioDeHoyInmutable();
        $anio = is_numeric($entrada['anio'] ?? null) ? max(2000, min((int) $entrada['anio'], $hoy->year)) : $hoy->year;
        $mes = is_numeric($entrada['mes'] ?? null) && (int) $entrada['mes'] >= 1 && (int) $entrada['mes'] <= 12 ? (int) $entrada['mes'] : null;
        $periodo = in_array($entrada['periodo'] ?? null, ['acumulado', 'anio', 'mes', 'rango'], true) ? (string) $entrada['periodo'] : 'acumulado';
        $fecha = fn (mixed $v): ?CarbonImmutable => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? (CarbonImmutable::createFromFormat('!Y-m-d', $v) ?: null) : null;

        [$desde, $hasta] = match ($periodo) {
            'mes' => [CarbonImmutable::create($anio, $mes ?? $hoy->month, 1), CarbonImmutable::create($anio, $mes ?? $hoy->month, 1)->endOfMonth()->startOfDay()],
            'anio' => [CarbonImmutable::create($anio, 1, 1), CarbonImmutable::create($anio, 12, 31)],
            'rango' => [$fecha($entrada['desde'] ?? null) ?? CarbonImmutable::create($anio, 1, 1), $fecha($entrada['hasta'] ?? null) ?? $hoy],
            default => [CarbonImmutable::create($anio, 1, 1), $anio === $hoy->year ? $hoy : CarbonImmutable::create($anio, 12, 31)],
        };

        if ($periodo === 'mes' && $mes === null) {
            $mes = $hoy->month;
        }

        if ($desde->gt($hasta)) {
            [$desde, $hasta] = [$hasta, $desde];
        }

        $corteParcial = $hasta->gt($hoy);
        $hasta = $hasta->min($hoy);
        $desde = $desde->min($hasta);

        $scope = $this->sucursalesVisibles($usuario);
        $empresaId = is_numeric($entrada['empresa_id'] ?? null) ? (int) $entrada['empresa_id'] : null;
        $sucursalId = is_numeric($entrada['sucursal_id'] ?? null) && $scope->contains((int) $entrada['sucursal_id']) ? (int) $entrada['sucursal_id'] : null;
        $sucursales = $scope;

        if ($empresaId !== null) {
            $deEmpresa = $sucursales->filter(fn (int $id): bool => $this->empresaDe($id) === $empresaId)->values();
            $empresaId = $deEmpresa->isNotEmpty() ? $empresaId : null;
            $sucursales = $deEmpresa->isNotEmpty() ? $deEmpresa : $sucursales;
        }

        if ($sucursalId !== null) {
            $sucursales = collect([$sucursalId]);
        }

        $analiticaId = is_numeric($entrada['causal_analitica_id'] ?? null) ? (int) $entrada['causal_analitica_id'] : null;

        return new FiltrosRotacion(
            periodo: $periodo,
            anio: $anio,
            mes: $periodo === 'mes' ? $mes : null,
            desde: $desde,
            hasta: $hasta,
            sucursales: $sucursales,
            empresaId: $empresaId,
            sucursalId: $sucursalId,
            departamentoId: is_numeric($entrada['departamento_id'] ?? null) ? (int) $entrada['departamento_id'] : null,
            puestoId: is_numeric($entrada['puesto_id'] ?? null) ? (int) $entrada['puesto_id'] : null,
            clasificacion: ClasificacionSalida::tryFrom((string) ($entrada['clasificacion'] ?? '')),
            causalAnaliticaId: $analiticaId !== null && $this->analiticas()->has($analiticaId) ? $analiticaId : null,
            rango: RangoAntiguedad::tryFrom((string) ($entrada['antiguedad'] ?? '')),
            fuente: in_array($entrada['fuente'] ?? null, ['sistema', 'historico'], true) ? (string) $entrada['fuente'] : 'todas',
            corteParcial: $corteParcial,
            // Las unidades externas no tienen sucursal ni empresa en People: solo
            // entran con alcance global y sin empresa ni sucursal elegida.
            externas: $empresaId === null && $sucursalId === null && $this->veTodo($usuario),
        );
    }

    /**
     * Eventos individuales del periodo y alcance. Con $detalle = true aplica
     * también puesto, área, tipo de salida, causal y antigüedad.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function eventos(FiltrosRotacion $f, bool $detalle = true): Collection
    {
        $clave = implode('|', [$f->desde->toDateString(), $f->hasta->toDateString(), $f->fuente, $f->sucursales->sort()->implode(','), $f->externas ? 'x' : '']);
        $todos = $this->eventosCache[$clave] ??= $this->cargarEventos($f);

        if (! $detalle || ! $f->conDetalle()) {
            return $todos;
        }

        return $todos->filter(fn (array $e): bool => ($f->puestoId === null || $e['puesto_id'] === $f->puestoId)
            && ($f->departamentoId === null || $e['departamento_id'] === $f->departamentoId)
            && ($f->clasificacion === null || $e['clasificacion'] === $f->clasificacion)
            && ($f->causalAnaliticaId === null || $e['causal_analitica_id'] === $f->causalAnaliticaId)
            && ($f->rango === null || $e['rango'] === $f->rango))->values();
    }

    /**
     * Una celda por (mes, sucursal) con bajas o con total del Excel. Siempre
     * sin filtros de detalle: es la base del total oficial.
     *
     * @return Collection<string, array{mes: string, sucursal_id: int, individuales: int, agregado: int|null, total: int, sin_desglose: int, voluntarias: int, involuntarias: int, sin_clasificar: int, discrepancia: bool}>
     */
    public function celdas(FiltrosRotacion $f): Collection
    {
        $eventos = $this->eventos($f, false)->groupBy(fn (array $e): string => $e['mes'].'|'.$e['sucursal_id']);
        $agregados = $this->agregados($f)->keyBy(fn (RotacionPeriodo $p): string => sprintf('%04d-%02d|%d', $p->anio, $p->mes, $p->sucursal_id));
        $inicioSistema = $this->mesInicioSistema()->format('Y-m');
        $conDetalle = $this->mesesConDetalleHistorico();
        $celdas = collect();

        foreach ($eventos->keys()->merge($agregados->keys())->unique() as $clave) {
            [$mes, $sucursal] = explode('|', (string) $clave);
            /** @var Collection<int, array<string, mixed>> $delaCelda */
            $delaCelda = $eventos->get($clave, collect());
            $periodo = $agregados->get($clave);
            $individuales = $delaCelda->count();
            $agregado = $periodo?->bajas;
            // Desde el inicio del módulo manda el sistema, y en un mes con
            // histórico individual cargado manda ese detalle: en ambos casos
            // la fila del Excel no se suma, solo se compara (discrepancia).
            $usaExcel = $agregado !== null && $mes < $inicioSistema && ! isset($conDetalle[$mes]);
            $total = $usaExcel ? max($individuales, (int) $agregado) : $individuales;
            $sinDesglose = $total - $individuales;
            $cuenta = fn (ClasificacionSalida $c): int => $delaCelda->where('clasificacion', $c)->count();

            // Sin individuales, el Excel aporta lo que traiga clasificado; el
            // resto de su total queda sin clasificar (nunca se reparte).
            $voluntarias = $individuales > 0 || ! $usaExcel ? $cuenta(ClasificacionSalida::Voluntaria) : (int) ($periodo->bajas_voluntarias ?? 0);
            $involuntarias = $individuales > 0 || ! $usaExcel ? $cuenta(ClasificacionSalida::Involuntaria) : (int) ($periodo->bajas_involuntarias ?? 0);

            $celdas->put($clave, [
                'mes' => $mes,
                'sucursal_id' => (int) $sucursal,
                'individuales' => $individuales,
                'agregado' => $agregado === null ? null : (int) $agregado,
                'total' => $total,
                'sin_desglose' => $sinDesglose,
                'voluntarias' => $voluntarias,
                'involuntarias' => $involuntarias,
                'sin_clasificar' => max(0, $total - $voluntarias - $involuntarias),
                'discrepancia' => $agregado !== null && ($individuales > 0 || ! $usaExcel) && $individuales !== (int) $agregado,
            ]);
        }

        return $celdas;
    }

    /**
     * Bajas de un mes para un conjunto de sucursales con la regla de celdas
     * (la usa el índice mensual del Tablero de RH y de Bajas).
     *
     * @param  Collection<int, int>  $sucursales
     */
    public function bajasDelMes(CarbonImmutable $mes, Collection $sucursales): ?int
    {
        $inicio = $mes->startOfMonth();

        if ($inicio->isAfter(FechaMexico::inicioDeHoyInmutable())) {
            return null;
        }

        $f = new FiltrosRotacion('mes', $inicio->year, $inicio->month, $inicio, $inicio->endOfMonth()->startOfDay()->min(FechaMexico::inicioDeHoyInmutable()), $sucursales, null, null, null, null, null, null, null, 'todas', false);
        $celdas = $this->celdas($f);

        return $celdas->isEmpty() && $this->agregados($f)->isEmpty() && $inicio->lt($this->mesInicioSistema())
            ? null
            : (int) $celdas->sum('total');
    }

    /**
     * Meses («Y-m») con histórico individual cargado: al menos una baja
     * histórica no descartada (válida o ligada a una del sistema), en
     * cualquier sucursal o unidad. En esos meses el detalle individual es la
     * fuente y el total del Excel solo se compara. Es global a propósito: si
     * el histórico cubre el mes, una sucursal sin bajas en él tiene 0, no el
     * total del Excel.
     *
     * @return array<string, true>
     */
    public function mesesConDetalleHistorico(): array
    {
        if ($this->mesesConDetalle === null) {
            $this->mesesConDetalle = [];

            foreach (BajaHistorica::query()->where('estado', '!=', BajaHistorica::DESCARTADA)->selectRaw('distinct substr(fecha_baja, 1, 7) as mes')->pluck('mes') as $mes) {
                $this->mesesConDetalle[(string) $mes] = true;
            }
        }

        return $this->mesesConDetalle;
    }

    /**
     * Nombre de cada ubicación de Rotación: sucursales de People (id > 0) y
     * unidades externas (id negativo, ver BajaHistorica::idUnidadExterna).
     *
     * @param  Collection<int, int>  $ids
     * @return Collection<int, string>
     */
    public function nombresUbicaciones(Collection $ids): Collection
    {
        $positivos = $ids->filter(fn (int $id): bool => $id > 0)->unique()->values();
        $nombres = $positivos->isEmpty() ? collect() : Sucursal::query()->withTrashed()->whereIn('id', $positivos->all())->pluck('nombre', 'id')->map(fn ($n): string => (string) $n);

        foreach ($this->unidadesExternas() as $id => $unidad) {
            if ($ids->contains($id)) {
                $nombres->put($id, $unidad['nombre']);
            }
        }

        return $nombres;
    }

    /**
     * Unidades externas con bajas históricas cargadas: id negativo => nombre y
     * grupo (unidad de negocio, de config/rotacion.php).
     *
     * @return array<int, array{nombre: string, grupo: string|null}>
     */
    public function unidadesExternas(): array
    {
        if ($this->externas === null) {
            $config = (array) config('rotacion.unidades_externas', []);
            $this->externas = [];

            foreach (BajaHistorica::query()->whereNotNull('unidad_externa')->distinct()->pluck('unidad_externa') as $unidad) {
                $nombre = (string) $unidad;
                $definida = $config[BajaHistorica::normalizar($nombre)] ?? null;
                $this->externas[BajaHistorica::idUnidadExterna($nombre)] = [
                    'nombre' => $nombre,
                    'grupo' => is_array($definida) && is_string($definida['grupo'] ?? null) ? $definida['grupo'] : null,
                ];
            }
        }

        return $this->externas;
    }

    public function mesInicioSistema(): CarbonImmutable
    {
        $mes = (string) config('bajas.tablero.mes_inicio_sistema', '2026-10');

        return CarbonImmutable::createFromFormat('!Y-m', $mes) ?: CarbonImmutable::create(2026, 10, 1);
    }

    /**
     * Totales del Excel que caen COMPLETOS dentro del rango (un total mensual
     * no se puede recortar a días).
     *
     * @return Collection<int, RotacionPeriodo>
     */
    public function agregados(FiltrosRotacion $f): Collection
    {
        if (! $f->incluyeAgregados()) {
            return collect();
        }

        return RotacionPeriodo::query()
            ->whereIn('sucursal_id', $f->sucursales->all())
            ->whereRaw('(anio * 100 + mes) between ? and ?', [(int) $f->desde->format('Ym'), (int) $f->hasta->format('Ym')])
            ->get()
            ->filter(function (RotacionPeriodo $p) use ($f): bool {
                $inicio = CarbonImmutable::create($p->anio, $p->mes, 1);

                // El mes en curso entra completo si el corte es hoy: su total
                // del Excel también va «a la fecha».
                return $inicio->gte($f->desde)
                    && ($inicio->endOfMonth()->startOfDay()->lte($f->hasta) || ($inicio->isSameMonth(FechaMexico::inicioDeHoyInmutable()) && $f->hasta->toDateString() === FechaMexico::hoy()));
            })
            ->values();
    }

    /**
     * Meses del Excel que el rango corta a la mitad: se informan, no se cuentan.
     *
     * @return list<string>
     */
    public function agregadosRecortados(FiltrosRotacion $f): array
    {
        if (! $f->incluyeAgregados()) {
            return [];
        }

        $dentro = $this->agregados($f)->map(fn (RotacionPeriodo $p): string => sprintf('%04d-%02d', $p->anio, $p->mes))->unique();

        return array_values(RotacionPeriodo::query()
            ->whereIn('sucursal_id', $f->sucursales->all())
            ->whereRaw('(anio * 100 + mes) between ? and ?', [(int) $f->desde->format('Ym'), (int) $f->hasta->format('Ym')])
            ->get()
            ->map(fn (RotacionPeriodo $p): string => sprintf('%04d-%02d', $p->anio, $p->mes))
            ->unique()
            ->diff($dentro)
            ->values()
            ->all());
    }

    /**
     * Catálogo de causales homologadas (id => modelo), activas o no.
     *
     * @return Collection<int, CausalAnalitica>
     */
    public function analiticas(): Collection
    {
        return $this->analiticas ??= CausalAnalitica::query()->ordenadas()->get()->keyBy('id');
    }

    public function idSinCausal(): ?int
    {
        return $this->analiticas()->first(fn (CausalAnalitica $c): bool => $c->clave === CausalAnalitica::SIN_CAUSAL)?->id;
    }

    /** Ve todas las sucursales (y las unidades externas) en Rotación. */
    public function veTodo(User $usuario): bool
    {
        return $usuario->can('bajas.gestionar') || $this->alcance->tieneAlcanceGlobal($usuario);
    }

    public function empresaDe(int $sucursalId): ?int
    {
        if ($this->empresas === null) {
            $this->empresas = [];

            foreach (Sucursal::query()->get(['id', 'empresa_id']) as $s) {
                $this->empresas[$s->id] = $s->empresa_id;
            }
        }

        return $this->empresas[$sucursalId] ?? null;
    }

    /** Olvida lo calculado (tras una corrección en la misma petición). */
    public function reiniciar(): void
    {
        $this->eventosCache = [];
        $this->analiticas = null;
        $this->catalogo = null;
        $this->mesesConDetalle = null;
        $this->externas = null;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function cargarEventos(FiltrosRotacion $f): Collection
    {
        $ids = $f->sucursales->all();
        $desde = $f->desde->toDateString();
        $hasta = $f->hasta->min(FechaMexico::inicioDeHoyInmutable())->toDateString();
        $eventos = collect();

        if ($f->fuente !== 'historico') {
            $bajas = Baja::query()->toBase()
                ->select(['id', 'colaborador_id', 'cierre_laboral_id', 'nombre_colaborador', 'numero_empleado', 'sucursal_id', 'puesto_id', 'departamento_id', 'fecha_ingreso', 'fecha_baja', 'forma_salida', 'causal_rotacion_id', 'causal_analitica_id', 'motivo_original', 'estatus', 'origen'])
                ->whereIn('estatus', [EstatusBaja::EnRevisionRh->value, EstatusBaja::EnRevisionJuridico->value, EstatusBaja::Cerrada->value])
                ->where('es_rotacion', true)
                ->whereIn('sucursal_id', $ids)
                ->whereNotNull('fecha_baja')
                ->whereDate('fecha_baja', '>=', $desde)
                ->whereDate('fecha_baja', '<=', $hasta)
                ->get();

            foreach ($bajas as $b) {
                $eventos->push($this->evento('sistema', (array) $b));
            }
        }

        if ($f->fuente !== 'sistema') {
            $historicas = BajaHistorica::query()->enAlcanceRotacion($f->sucursales, $f->externas)->toBase()
                ->select(['id', 'colaborador_id', 'baja_id', 'nombre', 'numero_empleado', 'sucursal_id', 'puesto_id', 'departamento_id', 'fecha_ingreso', 'fecha_baja', 'motivo_original', 'causal_analitica_id', 'estado', 'unidad_externa', 'antiguedad_dias', 'rango_antiguedad'])
                ->where('estado', BajaHistorica::VALIDA)
                ->whereDate('fecha_baja', '>=', $desde)
                ->whereDate('fecha_baja', '<=', $hasta)
                ->get();

            foreach ($historicas as $h) {
                $eventos->push($this->evento('historico', (array) $h));
            }
        }

        return $eventos->sortBy([['fecha_baja_texto', 'desc'], ['clave', 'asc']])->values();
    }

    /**
     * Normaliza una fila de `bajas` o de `bajas_historicas` al mismo arreglo.
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    private function evento(string $fuente, array $fila): array
    {
        $esSistema = $fuente === 'sistema';
        $entero = fn (string $campo): ?int => is_numeric($fila[$campo] ?? null) ? (int) $fila[$campo] : null;
        $ingreso = $this->fecha($fila['fecha_ingreso'] ?? null);
        $baja = $this->fecha($fila['fecha_baja'] ?? null) ?? FechaMexico::inicioDeHoyInmutable();
        $causalRotacionId = $esSistema ? $entero('causal_rotacion_id') : null;
        $forma = $esSistema && is_string($fila['forma_salida'] ?? null) ? FormaSalida::tryFrom($fila['forma_salida']) : null;
        [$analiticaId, $homologada, $clasificacion] = $this->resolverCausal(
            $entero('causal_analitica_id'),
            $causalRotacionId,
            $forma,
            $esSistema,
        );
        $id = (int) $entero('id');
        $externa = ! $esSistema && is_string($fila['unidad_externa'] ?? null) && $fila['unidad_externa'] !== '' && $entero('sucursal_id') === null
            ? $fila['unidad_externa']
            : null;
        // Una unidad externa se agrupa con un id negativo estable: nunca
        // coincide con una sucursal real ni con su plantilla.
        $sucursalId = $externa !== null ? BajaHistorica::idUnidadExterna($externa) : (int) $entero('sucursal_id');
        $rango = RangoAntiguedad::de($ingreso, $baja);
        $dias = $ingreso !== null && $baja->gte($ingreso) ? (int) $ingreso->diffInDays($baja) : null;
        $reportado = ! $esSistema && $ingreso === null ? RangoAntiguedad::tryFrom((string) ($fila['rango_antiguedad'] ?? '')) : null;
        $aproximada = $reportado !== null && $reportado !== RangoAntiguedad::SinFecha;

        // Sin fecha de ingreso, la antigüedad que reportó la fuente (p. ej.
        // «2.5 a») da el rango; los días son aproximados. La fecha real, en
        // cuanto se complete, siempre manda.
        if ($aproximada) {
            $rango = $reportado;
            $dias = $entero('antiguedad_dias');
        }
        $nombre = $fila[$esSistema ? 'nombre_colaborador' : 'nombre'] ?? '';
        $numero = $fila['numero_empleado'] ?? null;
        $motivo = $fila['motivo_original'] ?? null;
        $estatus = $fila['estatus'] ?? null;

        return [
            'clave' => sprintf('%s:%d', $fuente, $id),
            'fuente' => $fuente,
            'id' => $id,
            'baja_id' => $esSistema ? $id : null,
            'colaborador_id' => $entero('colaborador_id'),
            'cierre_laboral_id' => $esSistema ? $entero('cierre_laboral_id') : null,
            'nombre' => is_scalar($nombre) ? (string) $nombre : '',
            'numero_empleado' => is_scalar($numero) ? (string) $numero : null,
            'empresa_id' => $externa !== null ? null : $this->empresaDe($sucursalId),
            'sucursal_id' => $sucursalId,
            'unidad_externa' => $externa,
            'puesto_id' => $entero('puesto_id'),
            'departamento_id' => $entero('departamento_id'),
            'fecha_ingreso' => $ingreso,
            'fecha_baja' => $baja,
            'fecha_baja_texto' => $baja->toDateString(),
            'mes' => $baja->format('Y-m'),
            'motivo_original' => is_string($motivo) ? $motivo : null,
            'causal_rotacion_id' => $causalRotacionId,
            'causal_rotacion' => $causalRotacionId !== null ? ($this->catalogo()[$causalRotacionId]['nombre'] ?? null) : null,
            'forma' => $forma?->value,
            'estatus' => $esSistema && is_string($estatus) ? $estatus : null,
            'causal_analitica_id' => $analiticaId,
            'homologada' => $homologada,
            'clasificacion' => $clasificacion,
            'rango' => $rango,
            'antiguedad_dias' => $dias,
            'antiguedad_aproximada' => $aproximada,
            'fecha_inconsistente' => $ingreso !== null && $baja->lt($ingreso),
        ];
    }

    /**
     * Corrección de la baja → mapeo de su causal del catálogo → sin homologar
     * (por forma de salida) → sin causal.
     *
     * @return array{0: int|null, 1: bool, 2: ClasificacionSalida}
     */
    private function resolverCausal(?int $corregida, ?int $causalRotacionId, ?FormaSalida $forma, bool $esSistema): array
    {
        $analiticas = $this->analiticas();

        if ($corregida !== null && $analiticas->has($corregida)) {
            return [$corregida, true, $analiticas->get($corregida)->clasificacion];
        }

        if ($causalRotacionId !== null) {
            $mapeo = $this->catalogo()[$causalRotacionId] ?? null;

            if ($mapeo !== null && $mapeo['analitica_id'] !== null && $analiticas->has($mapeo['analitica_id'])) {
                return [$mapeo['analitica_id'], true, $analiticas->get($mapeo['analitica_id'])->clasificacion];
            }

            // Causal del catálogo aún sin homologar: se clasifica por su forma.
            $porForma = ($mapeo['forma'] ?? $forma) === FormaSalida::RenunciaVoluntaria ? ClasificacionSalida::Voluntaria : ClasificacionSalida::Involuntaria;

            return [null, false, $porForma];
        }

        if ($esSistema && $forma !== null) {
            return [null, false, $forma === FormaSalida::RenunciaVoluntaria ? ClasificacionSalida::Voluntaria : ClasificacionSalida::Involuntaria];
        }

        return [$this->idSinCausal(), true, ClasificacionSalida::SinClasificar];
    }

    /**
     * @return array<int, array{analitica_id: int|null, forma: FormaSalida, nombre: string, clave: string|null}>
     */
    private function catalogo(): array
    {
        if ($this->catalogo === null) {
            $this->catalogo = [];

            foreach (CausalRotacion::query()->get() as $c) {
                $this->catalogo[$c->id] = [
                    'analitica_id' => $c->causal_analitica_id,
                    'forma' => $c->forma(),
                    'nombre' => $c->etiquetaConClave(),
                    'clave' => $c->clave,
                ];
            }
        }

        return $this->catalogo;
    }

    private function fecha(mixed $valor): ?CarbonImmutable
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $valor)->startOfDay();
    }
}
