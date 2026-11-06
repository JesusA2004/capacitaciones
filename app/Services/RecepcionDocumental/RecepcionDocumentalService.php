<?php

namespace App\Services\RecepcionDocumental;

use App\Enums\EstadoCorteRecepcion;
use App\Enums\EstadoRecepcionDocumento;
use App\Enums\ReglaRecepcionDocumental;
use App\Models\GeneratedDocument;
use App\Models\RecepcionDocumentalCorte;
use App\Models\RecepcionDocumentalRenglon;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\RecepcionDocumental\Reglas\ReglaListasAsistencia;
use App\Support\Paginacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Recepción documental RH (docs/RECEPCION_DOCUMENTAL_RH.md): consultas y
 * acciones sobre los cortes mensuales. Lo usan IGUAL la web (Inertia) y la
 * API móvil; ningún cliente calcula reglas ni métricas.
 *
 * Alcance: todo listado pasa por AlcanceOrganizacionalService con el módulo
 * `recepcion_documental` (por sucursal del renglón). El estado del corte
 * es global (EstadoCorteService); las métricas en pantalla, del alcance.
 *
 * @phpstan-type Metricas array{esperados: int, recibidos: int, pendientes: int, no_recibidos: int, incidencias: int, no_aplica: int, renglones: int, cumplimiento: float}
 * @phpstan-type Filtros array{sucursal_id: int|null, sin_sucursal: bool, regla: string|null, tipo_documento: string|null, colaborador: string|null, estado: string|null, recibido: string|null}
 */
class RecepcionDocumentalService
{
    public const MODULO = 'recepcion_documental';

    public const PERMISO_VER = 'recepcion_documental.ver';

    public const PERMISO_GENERAR = 'recepcion_documental.generar';

    public const PERMISO_RECIBIR = 'recepcion_documental.recibir';

    public const PERMISO_OBSERVAR = 'recepcion_documental.observar';

    public const PERMISO_CERRAR = 'recepcion_documental.cerrar';

    public const PERMISO_REABRIR = 'recepcion_documental.reabrir';

    public const PERMISO_DESCARGAR_PDF = 'recepcion_documental.descargar_pdf';

    public function __construct(
        private readonly MaterializadorCorteService $materializador,
        private readonly EstadoCorteService $estados,
        private readonly AuditoriaService $auditoria,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly DocumentoStorageService $almacen,
        private readonly CatalogoReglasRecepcion $catalogo,
        private readonly ReglaListasAsistencia $listas,
    ) {}

    /** @var array<string, list<array{id: int, nombre: string, numero_empleado: string|null}>> */
    private array $integrantesMemo = [];

    // ───────────────────────── Cortes ─────────────────────────

    /** El corte del mes (lo crea «En preparación» si no existe). */
    public function corteDelPeriodo(PeriodoRecepcion $periodo): RecepcionDocumentalCorte
    {
        return RecepcionDocumentalCorte::query()->firstOrCreate(
            ['periodo' => $periodo->clave()],
            [
                'anio' => $periodo->anio,
                'mes' => $periodo->mes,
                'fecha_inicio' => $periodo->inicio()->toDateString(),
                'fecha_fin' => $periodo->fin()->toDateString(),
                'estado' => EstadoCorteRecepcion::EnPreparacion,
            ],
        );
    }

    /**
     * Crea (si falta) y calcula el corte del periodo. Un corte cerrado nunca
     * se recalcula: con $omitirCerrado (comando programado) se devuelve tal
     * cual; si no, responde 422.
     *
     * @return array{corte: RecepcionDocumentalCorte, conteo: array{nuevos: int, ligados: int, no_aplica: int, reactivados: int, actualizados: int}|null}
     */
    public function generar(PeriodoRecepcion $periodo, ?User $actor, bool $omitirCerrado = false): array
    {
        if ($periodo->esFuturo()) {
            throw ValidationException::withMessages(['periodo' => sprintf('%s todavía no empieza: no hay eventos que conciliar.', ucfirst($periodo->etiqueta()))]);
        }

        $corte = $this->corteDelPeriodo($periodo);

        if ($corte->estaCerrado() && $omitirCerrado) {
            return ['corte' => $corte, 'conteo' => null];
        }

        $conteo = $this->materializador->materializar($corte, $actor);

        return ['corte' => $corte->refresh(), 'conteo' => $conteo];
    }

    /**
     * Cortes con sus métricas DENTRO del alcance de quien consulta.
     *
     * @return LengthAwarePaginator<int, RecepcionDocumentalCorte>
     */
    public function listarCortes(User $usuario, mixed $porPagina = null): LengthAwarePaginator
    {
        return RecepcionDocumentalCorte::query()
            ->with(['cerradoPor:id,name', 'reabiertoPor:id,name'])
            ->orderByDesc('anio')->orderByDesc('mes')
            ->paginate(Paginacion::porPagina(12, $porPagina))
            ->withQueryString();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, Metricas>
     */
    public function metricasPorCorte(User $usuario, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $filas = $this->agregar($this->visibles($usuario)->whereIn('corte_id', $ids)->groupBy('corte_id'), ['corte_id']);
        $resultado = [];

        foreach ($ids as $id) {
            $resultado[$id] = $filas[$id] ?? self::metricasVacias();
        }

        return $resultado;
    }

    /**
     * @param  Metricas  $metricas
     * @return array<string, mixed>
     */
    public function filaCorte(RecepcionDocumentalCorte $corte, array $metricas): array
    {
        $periodo = PeriodoRecepcion::delCorte($corte);

        return [
            'id' => $corte->id,
            'periodo' => $corte->periodo,
            'etiqueta' => ucfirst($periodo->etiqueta()),
            'fecha_inicio' => $corte->fecha_inicio->toDateString(),
            'fecha_fin' => $corte->fecha_fin->toDateString(),
            'dias' => $periodo->dias(),
            'estado' => $corte->estado->value,
            'estado_etiqueta' => $corte->estado->etiqueta(),
            'materializado_en' => $corte->materializado_en?->toIso8601String(),
            'cerrado_en' => $corte->cerrado_en?->toIso8601String(),
            'cerrado_por' => $corte->cerradoPor?->name,
            'observaciones_cierre' => $corte->observaciones_cierre,
            'reabierto_conteo' => $corte->reabierto_conteo,
            'reabierto_en' => $corte->reabierto_en?->toIso8601String(),
            'reabierto_por' => $corte->reabiertoPor?->name,
            'motivo_reapertura' => $corte->motivo_reapertura,
            'metricas' => $metricas,
        ];
    }

    /**
     * Métricas del corte en el alcance del usuario (con filtros opcionales).
     *
     * @param  array<string, mixed>  $filtros
     * @return Metricas
     */
    public function metricas(RecepcionDocumentalCorte $corte, User $usuario, array $filtros = []): array
    {
        $consulta = $this->filtrar($this->visibles($usuario)->where('corte_id', $corte->id), $this->normalizarFiltros($filtros));

        return $this->agregar($consulta)[0] ?? self::metricasVacias();
    }

    /**
     * Agrupaciones del corte (alcance del usuario): por sucursal (las que
     * tienen pendientes primero), por tipo documental y por regla/origen
     * (todas las reglas activas, también las que no tuvieron eventos).
     *
     * @return array{sucursales: list<array<string, mixed>>, tipos: list<array<string, mixed>>, reglas: list<array<string, mixed>>}
     */
    public function agrupaciones(RecepcionDocumentalCorte $corte, User $usuario): array
    {
        $base = fn (): Builder => $this->visibles($usuario)->where('corte_id', $corte->id);

        $sucursales = [];

        foreach ($this->agregarFilas($base()->groupBy('sucursal_id', 'sucursal_nombre'), ['sucursal_id', 'sucursal_nombre']) as $fila) {
            $sucursales[] = ['sucursal_id' => $fila['sucursal_id'], 'nombre' => $fila['sucursal_nombre'] ?? 'Sin sucursal', 'metricas' => $fila['metricas']];
        }

        usort($sucursales, fn (array $a, array $b): int => [$b['metricas']['pendientes'] + $b['metricas']['no_recibidos'], $a['nombre']] <=> [$a['metricas']['pendientes'] + $a['metricas']['no_recibidos'], $b['nombre']]);

        $tipos = [];

        foreach ($this->agregarFilas($base()->groupBy('tipo_documento', 'tipo_etiqueta'), ['tipo_documento', 'tipo_etiqueta']) as $fila) {
            $tipos[] = ['tipo_documento' => $fila['tipo_documento'], 'etiqueta' => $fila['tipo_etiqueta'], 'metricas' => $fila['metricas']];
        }

        usort($tipos, fn (array $a, array $b): int => strcmp((string) $a['etiqueta'], (string) $b['etiqueta']));

        $porRegla = [];

        foreach ($this->agregarFilas($base()->groupBy('regla'), ['regla']) as $fila) {
            $porRegla[(string) $fila['regla']] = $fila['metricas'];
        }

        $periodo = PeriodoRecepcion::delCorte($corte);
        $reglas = [];

        foreach ($this->catalogo->resumen() as $regla) {
            $caso = ReglaRecepcionDocumental::from($regla['clave']);

            if (! $regla['activa'] && ! isset($porRegla[$caso->value])) {
                continue;
            }

            $reglas[] = [
                'regla' => $caso->value,
                'etiqueta' => $caso->etiqueta(),
                'orden' => $caso->orden(),
                'activa' => $regla['activa'],
                'sin_eventos' => $caso->sinEventos($periodo->etiqueta()),
                'metricas' => $porRegla[$caso->value] ?? self::metricasVacias(),
            ];
        }

        return ['sucursales' => $sucursales, 'tipos' => $tipos, 'reglas' => $reglas];
    }

    /**
     * Opciones de los filtros (solo lo que hay en el corte y en el alcance).
     *
     * @return array{sucursales: list<array{value: int|string, label: string}>, tipos: list<array{value: string, label: string}>, reglas: list<array{value: string, label: string}>, estados: list<array{value: string, label: string}>}
     */
    public function opcionesFiltros(RecepcionDocumentalCorte $corte, User $usuario): array
    {
        $base = fn (): Builder => $this->visibles($usuario)->where('corte_id', $corte->id);

        $sucursales = [];

        foreach ($base()->select(['sucursal_id', 'sucursal_nombre'])->distinct()->orderBy('sucursal_nombre')->toBase()->get() as $fila) {
            $sucursales[] = $fila->sucursal_id === null
                ? ['value' => 'sin', 'label' => 'Sin sucursal']
                : ['value' => (int) $fila->sucursal_id, 'label' => (string) ($fila->sucursal_nombre ?? sprintf('Sucursal %d', $fila->sucursal_id))];
        }

        $tipos = [];

        foreach ($base()->select(['tipo_documento', 'tipo_etiqueta'])->distinct()->orderBy('tipo_etiqueta')->toBase()->get() as $fila) {
            $tipos[] = ['value' => (string) $fila->tipo_documento, 'label' => (string) $fila->tipo_etiqueta];
        }

        $casos = ReglaRecepcionDocumental::cases();
        usort($casos, fn (ReglaRecepcionDocumental $a, ReglaRecepcionDocumental $b): int => $a->orden() <=> $b->orden());

        return [
            'sucursales' => $sucursales,
            'tipos' => $tipos,
            'reglas' => array_map(fn (ReglaRecepcionDocumental $r): array => ['value' => $r->value, 'label' => $r->etiqueta()], $casos),
            'estados' => array_map(fn (EstadoRecepcionDocumento $e): array => ['value' => $e->value, 'label' => $e->etiqueta()], EstadoRecepcionDocumento::cases()),
        ];
    }

    // ───────────────────────── Renglones ─────────────────────────

    /**
     * Renglones del corte, paginados, ordenados SUCURSAL → evento (ALTAS,
     * NÓMINA, …) → persona → documento, para agruparlos en pantalla.
     *
     * @param  array<string, mixed>  $filtros
     * @return LengthAwarePaginator<int, RecepcionDocumentalRenglon>
     */
    public function renglones(RecepcionDocumentalCorte $corte, User $usuario, array $filtros = [], mixed $porPagina = null): LengthAwarePaginator
    {
        return $this->ordenados($this->filtrar($this->visibles($usuario)->where('corte_id', $corte->id), $this->normalizarFiltros($filtros)))
            ->with(['recibidoPor:id,name', 'documento:id,estado_flujo,titulo,clave_plantilla'])
            ->paginate(Paginacion::porPagina(50, $porPagina))
            ->withQueryString();
    }

    /**
     * Todos los renglones (sin paginar, con tope) para el PDF operativo.
     *
     * @param  array<string, mixed>  $filtros
     * @return list<RecepcionDocumentalRenglon>
     */
    public function todosLosRenglones(RecepcionDocumentalCorte $corte, User $usuario, array $filtros = []): array
    {
        return array_values($this->ordenados($this->filtrar($this->visibles($usuario)->where('corte_id', $corte->id), $this->normalizarFiltros($filtros)))
            ->limit(Paginacion::maximoTodos() * 5)
            ->get()
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function filaRenglon(RecepcionDocumentalRenglon $r): array
    {
        $documento = $r->relationLoaded('documento') ? $r->documento : null;

        return [
            'id' => $r->id,
            'corte_id' => $r->corte_id,
            'regla' => $r->regla->value,
            'regla_etiqueta' => $r->regla->etiqueta(),
            'regla_orden' => $r->regla->orden(),
            'tipo_documento' => $r->tipo_documento,
            'tipo_etiqueta' => $r->tipo_etiqueta,
            'origen' => ['tipo' => class_basename($r->origen_type), 'id' => $r->origen_id],
            'colaborador' => [
                'id' => $r->colaborador_id,
                'nombre' => $r->colaborador_nombre,
                'numero_empleado' => $r->numero_empleado,
                'puesto' => $r->puesto_nombre,
            ],
            'sucursal' => ['id' => $r->sucursal_id, 'nombre' => $r->sucursal_nombre ?? 'Sin sucursal'],
            'fecha_evento' => $r->fecha_evento?->toDateString(),
            'evento_descripcion' => $r->evento_descripcion,
            'cantidad_esperada' => $r->cantidad_esperada,
            'cantidad_recibida' => $r->cantidad_recibida,
            'integrantes' => $this->integrantesDe($r),
            'estado' => $r->estado->value,
            'estado_etiqueta' => $r->estado->etiqueta(),
            'justificacion' => $r->justificacion,
            'no_aplica_automatico' => $r->no_aplica_automatico,
            'recibido_en' => $r->recibido_en?->toIso8601String(),
            'recibido_por' => $r->relationLoaded('recibidoPor') ? $r->recibidoPor?->name : null,
            'observaciones' => $r->observaciones,
            'incidencia' => $r->incidencia,
            'tiene_evidencia' => $r->evidencia_path !== null,
            'evidencia_nombre' => $r->evidencia_nombre,
            'documento_digital' => $documento instanceof GeneratedDocument ? [
                'id' => $documento->id,
                'titulo' => $documento->titulo,
                'estado' => $documento->estado_flujo?->etiqueta(),
            ] : null,
            'actualizado_en' => $r->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Lote de listas de asistencia: los nombres de los activos de esa
     * sucursal y semana (mismo criterio que la cantidad). Otras reglas: null.
     *
     * @return list<array{id: int, nombre: string, numero_empleado: string|null}>|null
     */
    private function integrantesDe(RecepcionDocumentalRenglon $r): ?array
    {
        if ($r->regla !== ReglaRecepcionDocumental::ListasAsistencia || $r->sucursal_id === null) {
            return null;
        }

        $clave = sprintf('%d|%s', $r->sucursal_id, $r->tipo_documento);

        return $this->integrantesMemo[$clave] ??= $this->listas->integrantes($r->sucursal_id, $r->tipo_documento);
    }

    /** Renglón del corte dentro del alcance (404 si no lo ve). */
    public function renglonVisible(User $usuario, int $id): RecepcionDocumentalRenglon
    {
        return $this->visibles($usuario)->with(['recibidoPor:id,name', 'documento:id,estado_flujo,titulo,clave_plantilla', 'corte'])->findOrFail($id);
    }

    public function alcanzaRenglon(User $usuario, RecepcionDocumentalRenglon $renglon): bool
    {
        return $this->visibles($usuario)->whereKey($renglon->id)->exists();
    }

    /**
     * Marca la recepción del ORIGINAL: recibido, recibido con observación,
     * no recibido, no aplica (con justificación) o de vuelta a pendiente.
     * Cada cambio queda en la bitácora con el antes y el después.
     *
     * @param  array{observaciones?: string|null, justificacion?: string|null, cantidad_recibida?: int|null}  $datos
     */
    public function marcar(RecepcionDocumentalRenglon $renglon, User $actor, EstadoRecepcionDocumento $estado, array $datos = []): RecepcionDocumentalRenglon
    {
        $observaciones = self::texto($datos['observaciones'] ?? null);
        $justificacion = self::texto($datos['justificacion'] ?? null);

        if ($estado === EstadoRecepcionDocumento::NoAplica && $justificacion === null) {
            throw ValidationException::withMessages(['justificacion' => 'Escribe por qué este original no aplica.']);
        }

        if (($estado === EstadoRecepcionDocumento::RecibidoConObservacion || $estado === EstadoRecepcionDocumento::NoRecibido) && $observaciones === null) {
            throw ValidationException::withMessages(['observaciones' => 'Escribe la observación (qué llegó mal o por qué no llegó).']);
        }

        $actualizado = DB::transaction(function () use ($renglon, $actor, $estado, $observaciones, $justificacion, $datos): RecepcionDocumentalRenglon {
            $actual = RecepcionDocumentalRenglon::query()->with('corte')->lockForUpdate()->findOrFail($renglon->id);

            if ($actual->corte->estaCerrado()) {
                throw ValidationException::withMessages(['estado' => 'El corte está cerrado: reábrelo con motivo para cambiar la recepción.']);
            }

            $antes = $this->instantanea($actual);
            $recibido = $estado->esRecibido();
            $cantidad = $recibido
                ? max(0, min($actual->cantidad_esperada, (int) ($datos['cantidad_recibida'] ?? $actual->cantidad_esperada)))
                : 0;

            $actual->fill([
                'estado' => $estado,
                'cantidad_recibida' => $cantidad,
                'recibido_en' => $recibido ? ($actual->estado->esRecibido() && $actual->recibido_en !== null ? $actual->recibido_en : Carbon::now()) : null,
                'recibido_por' => $recibido ? ($actual->estado->esRecibido() && $actual->recibido_por !== null ? $actual->recibido_por : $actor->id) : null,
                'observaciones' => $estado === EstadoRecepcionDocumento::Pendiente || $estado === EstadoRecepcionDocumento::Recibido ? ($observaciones ?? $actual->observaciones) : $observaciones,
                'justificacion' => $estado === EstadoRecepcionDocumento::NoAplica ? $justificacion : null,
                'no_aplica_automatico' => false,
                'incidencia' => $estado->esIncidencia(),
                'actualizado_por' => $actor->id,
            ])->save();

            $this->auditoria->registrar('recepcion_documental_renglon_actualizado', $actual, $actor, [
                'corte' => $actual->corte->periodo,
                'tipo' => $actual->tipo_documento,
                'antes' => $antes,
                'despues' => $this->instantanea($actual),
            ]);

            return $actual;
        });

        $this->estados->recalcular($actualizado->corte);

        return $actualizado->load(['recibidoPor:id,name', 'documento:id,estado_flujo,titulo,clave_plantilla']);
    }

    /** Evidencia opcional (foto de la guía, escaneo) en el disco de expedientes. */
    public function subirEvidencia(RecepcionDocumentalRenglon $renglon, UploadedFile $archivo, User $actor): RecepcionDocumentalRenglon
    {
        $renglon->loadMissing('corte');

        if ($renglon->corte->estaCerrado()) {
            throw ValidationException::withMessages(['archivo' => 'El corte está cerrado: reábrelo con motivo para agregar evidencia.']);
        }

        $extension = strtolower($archivo->getClientOriginalExtension() ?: ($archivo->extension() ?: 'pdf'));
        $ruta = sprintf('%s/%s/evidencias/renglon-%d-%s.%s', $this->carpeta(), $renglon->corte->periodo, $renglon->id, Str::lower(Str::random(10)), $extension);
        $this->almacen->guardar($archivo, $ruta);

        $anterior = $renglon->evidencia_nombre;
        $renglon->forceFill(['evidencia_path' => $ruta, 'evidencia_nombre' => mb_substr($archivo->getClientOriginalName(), 0, 255), 'actualizado_por' => $actor->id])->save();
        // El archivo anterior se conserva en el disco (nunca se borra evidencia).
        $this->auditoria->registrar('recepcion_documental_evidencia', $renglon, $actor, ['corte' => $renglon->corte->periodo, 'archivo' => $renglon->evidencia_nombre, 'reemplaza' => $anterior]);

        return $renglon;
    }

    public function descargarEvidencia(RecepcionDocumentalRenglon $renglon): StreamedResponse
    {
        abort_if($renglon->evidencia_path === null, 404, 'Este renglón no tiene evidencia.');

        return $this->almacen->respuesta($renglon->evidencia_path, [
            'Content-Disposition' => sprintf('inline; filename="%s"', str_replace('"', '', $renglon->evidencia_nombre ?? 'evidencia')),
        ]);
    }

    /**
     * Historial del renglón (bitácora): quién lo marcó, cuándo y cómo estaba.
     *
     * @return list<array{accion: string, por: string|null, en: string|null, propiedades: array<string, mixed>}>
     */
    public function historial(RecepcionDocumentalRenglon $renglon): array
    {
        return $this->auditoria->historial($renglon, null, 50);
    }

    /**
     * @return list<array{accion: string, por: string|null, en: string|null, propiedades: array<string, mixed>}>
     */
    public function historialCorte(RecepcionDocumentalCorte $corte): array
    {
        return $this->auditoria->historial($corte, null, 30);
    }

    // ───────────────────────── Cierre y reapertura ─────────────────────────

    /**
     * Cierra el corte: antes se recalcula por última vez; con originales
     * pendientes o no recibidos exige explicar por qué. Ya cerrado, su
     * snapshot y sus renglones no cambian.
     */
    public function cerrar(RecepcionDocumentalCorte $corte, User $actor, ?string $observaciones = null): RecepcionDocumentalCorte
    {
        $observaciones = self::texto($observaciones);

        if ($corte->estaCerrado()) {
            throw ValidationException::withMessages(['corte' => 'Este corte ya está cerrado.']);
        }

        if ($corte->materializado_en === null) {
            throw ValidationException::withMessages(['corte' => 'Calcula el corte antes de cerrarlo.']);
        }

        $this->materializador->materializar($corte, $actor);
        $corte->refresh();
        $metricas = $this->agregar(RecepcionDocumentalRenglon::query()->where('corte_id', $corte->id))[0] ?? self::metricasVacias();
        $faltan = $metricas['pendientes'] + $metricas['no_recibidos'];

        if ($faltan > 0 && $observaciones === null) {
            throw ValidationException::withMessages(['observaciones' => sprintf('Hay %d original(es) sin recibir: explica por qué se cierra así.', $faltan)]);
        }

        DB::transaction(function () use ($corte, $actor, $observaciones): void {
            $bloqueado = RecepcionDocumentalCorte::query()->lockForUpdate()->findOrFail($corte->id);

            if ($bloqueado->estaCerrado()) {
                throw ValidationException::withMessages(['corte' => 'Este corte ya está cerrado.']);
            }

            $bloqueado->forceFill([
                'estado' => EstadoCorteRecepcion::Cerrado,
                'cerrado_en' => Carbon::now(),
                'cerrado_por' => $actor->id,
                'observaciones_cierre' => $observaciones,
            ])->save();
        });

        $this->auditoria->registrar('recepcion_documental_corte_cerrado', $corte, $actor, ['periodo' => $corte->periodo, 'observaciones' => $observaciones, 'metricas' => $metricas]);

        return $corte->refresh();
    }

    /**
     * Reabre un corte cerrado (mismo criterio que las reaperturas del
     * sistema: permiso explícito + motivo obligatorio + constancia en la
     * bitácora con la decisión anterior). Vuelve a su estado calculado.
     */
    public function reabrir(RecepcionDocumentalCorte $corte, User $actor, string $motivo): RecepcionDocumentalCorte
    {
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw ValidationException::withMessages(['motivo' => 'Escribe el motivo para reabrir el corte.']);
        }

        if (! $corte->estaCerrado()) {
            throw ValidationException::withMessages(['corte' => 'Solo un corte cerrado se puede reabrir.']);
        }

        $anterior = ['cerrado_en' => $corte->cerrado_en?->toIso8601String(), 'cerrado_por' => $corte->cerrado_por, 'observaciones_cierre' => $corte->observaciones_cierre];

        DB::transaction(function () use ($corte, $actor, $motivo): void {
            $bloqueado = RecepcionDocumentalCorte::query()->lockForUpdate()->findOrFail($corte->id);

            if (! $bloqueado->estaCerrado()) {
                throw ValidationException::withMessages(['corte' => 'Solo un corte cerrado se puede reabrir.']);
            }

            $bloqueado->forceFill([
                'estado' => EstadoCorteRecepcion::Abierto,
                'reabierto_conteo' => $bloqueado->reabierto_conteo + 1,
                'reabierto_en' => Carbon::now(),
                'reabierto_por' => $actor->id,
                'motivo_reapertura' => $motivo,
            ])->save();
        });

        $corte->refresh();
        $this->estados->recalcular($corte);
        $this->auditoria->registrar('recepcion_documental_corte_reabierto', $corte, $actor, ['periodo' => $corte->periodo, 'motivo' => $motivo, 'decision_anterior' => $anterior]);

        return $corte->refresh();
    }

    /**
     * Qué puede hacer el usuario en este corte (misma Policy que cada endpoint).
     *
     * @return array{generar: bool, recibir: bool, observar: bool, cerrar: bool, reabrir: bool, descargar_pdf: bool}
     */
    public function permisosDe(User $usuario, RecepcionDocumentalCorte $corte): array
    {
        $gate = Gate::forUser($usuario);

        return [
            'generar' => $gate->allows('generar', $corte),
            'recibir' => $usuario->can(self::PERMISO_RECIBIR) && ! $corte->estaCerrado(),
            'observar' => $usuario->can(self::PERMISO_OBSERVAR) && ! $corte->estaCerrado(),
            'cerrar' => $gate->allows('cerrar', $corte),
            'reabrir' => $gate->allows('reabrir', $corte),
            'descargar_pdf' => $gate->allows('descargarPdf', $corte),
        ];
    }

    // ───────────────────────── Internos ─────────────────────────

    /**
     * @return Builder<RecepcionDocumentalRenglon>
     */
    public function visibles(User $usuario): Builder
    {
        return $this->alcance->limitarPorSucursal(RecepcionDocumentalRenglon::query(), $usuario, 'sucursal_id', self::MODULO);
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return Filtros
     */
    public function normalizarFiltros(array $entrada): array
    {
        $sucursal = $entrada['sucursal_id'] ?? null;
        $texto = fn (string $clave): ?string => is_string($entrada[$clave] ?? null) && trim((string) $entrada[$clave]) !== '' ? mb_substr(trim((string) $entrada[$clave]), 0, 120) : null;
        $regla = $texto('regla');
        $estado = $texto('estado');
        $recibido = $texto('recibido');

        return [
            'sucursal_id' => is_numeric($sucursal) ? (int) $sucursal : null,
            'sin_sucursal' => $sucursal === 'sin',
            'regla' => $regla !== null && ReglaRecepcionDocumental::tryFrom($regla) !== null ? $regla : null,
            'tipo_documento' => $texto('tipo_documento'),
            'colaborador' => $texto('colaborador'),
            'estado' => $estado !== null && EstadoRecepcionDocumento::tryFrom($estado) !== null ? $estado : null,
            'recibido' => in_array($recibido, ['si', 'no'], true) ? $recibido : null,
        ];
    }

    /**
     * @param  Builder<RecepcionDocumentalRenglon>  $consulta
     * @param  Filtros  $f
     * @return Builder<RecepcionDocumentalRenglon>
     */
    private function filtrar(Builder $consulta, array $f): Builder
    {
        $recibidos = [EstadoRecepcionDocumento::Recibido->value, EstadoRecepcionDocumento::RecibidoConObservacion->value];

        return $consulta
            ->when($f['sucursal_id'] !== null, fn (Builder $q) => $q->where('sucursal_id', $f['sucursal_id']))
            ->when($f['sin_sucursal'], fn (Builder $q) => $q->whereNull('sucursal_id'))
            ->when($f['regla'] !== null, fn (Builder $q) => $q->where('regla', $f['regla']))
            ->when($f['tipo_documento'] !== null, fn (Builder $q) => $q->where('tipo_documento', $f['tipo_documento']))
            ->when($f['estado'] !== null, fn (Builder $q) => $q->where('estado', $f['estado']))
            ->when($f['recibido'] === 'si', fn (Builder $q) => $q->whereIn('estado', $recibidos))
            ->when($f['recibido'] === 'no', fn (Builder $q) => $q->whereNotIn('estado', $recibidos))
            ->when($f['colaborador'] !== null, function (Builder $q) use ($f): void {
                $termino = sprintf('%%%s%%', str_replace(['%', '_'], ['\%', '\_'], (string) $f['colaborador']));
                $q->where(fn (Builder $s) => $s->where('colaborador_nombre', 'like', $termino)->orWhere('numero_empleado', 'like', $termino));
            });
    }

    /**
     * @param  Builder<RecepcionDocumentalRenglon>  $consulta
     * @return Builder<RecepcionDocumentalRenglon>
     */
    private function ordenados(Builder $consulta): Builder
    {
        $casos = [];
        $valores = [];

        foreach (ReglaRecepcionDocumental::cases() as $regla) {
            $casos[] = 'WHEN ? THEN ?';
            $valores[] = $regla->value;
            $valores[] = $regla->orden();
        }

        return $consulta
            ->orderByRaw('CASE WHEN sucursal_nombre IS NULL THEN 1 ELSE 0 END')
            ->orderBy('sucursal_nombre')
            ->orderByRaw(sprintf('CASE regla %s ELSE 99 END', implode(' ', $casos)), $valores)
            ->orderBy('colaborador_nombre')
            ->orderBy('origen_id')
            ->orderBy('tipo_etiqueta')
            ->orderBy('id');
    }

    /**
     * Agregados SUM/COUNT de una consulta (agrupada o no). Devuelve las
     * métricas indexadas por corte_id si la consulta lo selecciona; si no,
     * en la posición 0.
     *
     * @param  Builder<RecepcionDocumentalRenglon>  $consulta
     * @param  list<string>  $grupo
     * @return array<int, Metricas>
     */
    private function agregar(Builder $consulta, array $grupo = []): array
    {
        $resultado = [];

        foreach ($this->agregarFilas($consulta, $grupo) as $fila) {
            $resultado[isset($fila['corte_id']) ? (int) $fila['corte_id'] : 0] = $fila['metricas'];
        }

        return $resultado;
    }

    /**
     * @param  Builder<RecepcionDocumentalRenglon>  $consulta
     * @param  list<string>  $grupo  Columnas del GROUP BY (también se seleccionan).
     * @return list<array<string, mixed>>
     */
    private function agregarFilas(Builder $consulta, array $grupo = []): array
    {
        $r = [EstadoRecepcionDocumento::Recibido->value, EstadoRecepcionDocumento::RecibidoConObservacion->value];

        $filas = $consulta->select($grupo)->addSelect([
            DB::raw(sprintf("SUM(CASE WHEN estado <> '%s' THEN cantidad_esperada ELSE 0 END) AS m_esperados", EstadoRecepcionDocumento::NoAplica->value)),
            DB::raw(sprintf("SUM(CASE WHEN estado IN ('%s', '%s') THEN cantidad_recibida ELSE 0 END) AS m_recibidos", $r[0], $r[1])),
            DB::raw(sprintf("SUM(CASE WHEN estado = '%s' THEN cantidad_esperada ELSE 0 END) AS m_pendientes", EstadoRecepcionDocumento::Pendiente->value)),
            DB::raw(sprintf("SUM(CASE WHEN estado = '%s' THEN cantidad_esperada ELSE 0 END) AS m_no_recibidos", EstadoRecepcionDocumento::NoRecibido->value)),
            DB::raw('SUM(CASE WHEN incidencia = 1 THEN 1 ELSE 0 END) AS m_incidencias'),
            DB::raw(sprintf("SUM(CASE WHEN estado = '%s' THEN 1 ELSE 0 END) AS m_no_aplica", EstadoRecepcionDocumento::NoAplica->value)),
            DB::raw('COUNT(*) AS m_renglones'),
        ])->toBase()->get();

        $resultado = [];

        foreach ($filas as $fila) {
            $datos = (array) $fila;

            if ((int) ($datos['m_renglones'] ?? 0) === 0) {
                continue;
            }

            $esperados = (int) ($datos['m_esperados'] ?? 0);
            $recibidos = (int) ($datos['m_recibidos'] ?? 0);
            $metricas = [
                'esperados' => $esperados,
                'recibidos' => $recibidos,
                'pendientes' => (int) ($datos['m_pendientes'] ?? 0),
                'no_recibidos' => (int) ($datos['m_no_recibidos'] ?? 0),
                'incidencias' => (int) ($datos['m_incidencias'] ?? 0),
                'no_aplica' => (int) ($datos['m_no_aplica'] ?? 0),
                'renglones' => (int) ($datos['m_renglones'] ?? 0),
                'cumplimiento' => self::cumplimiento($recibidos, $esperados),
            ];

            $extra = array_filter($datos, fn (string $clave): bool => ! str_starts_with($clave, 'm_'), ARRAY_FILTER_USE_KEY);
            $resultado[] = [...$extra, 'metricas' => $metricas];
        }

        return $resultado;
    }

    public static function cumplimiento(int $recibidos, int $esperados): float
    {
        return $esperados > 0 ? round(min(100, $recibidos * 100 / $esperados), 1) : 0.0;
    }

    /**
     * @return Metricas
     */
    public static function metricasVacias(): array
    {
        return ['esperados' => 0, 'recibidos' => 0, 'pendientes' => 0, 'no_recibidos' => 0, 'incidencias' => 0, 'no_aplica' => 0, 'renglones' => 0, 'cumplimiento' => 0.0];
    }

    /**
     * @return array<string, mixed>
     */
    private function instantanea(RecepcionDocumentalRenglon $r): array
    {
        return [
            'estado' => $r->estado->value,
            'cantidad_recibida' => $r->cantidad_recibida,
            'observaciones' => $r->observaciones,
            'justificacion' => $r->justificacion,
            'recibido_en' => $r->recibido_en?->toIso8601String(),
        ];
    }

    private function carpeta(): string
    {
        return trim((string) config('recepcion_documental.carpeta', 'RecepcionDocumental'), '/');
    }

    private static function texto(mixed $valor): ?string
    {
        return is_string($valor) && trim($valor) !== '' ? mb_substr(trim($valor), 0, 2000) : null;
    }
}
