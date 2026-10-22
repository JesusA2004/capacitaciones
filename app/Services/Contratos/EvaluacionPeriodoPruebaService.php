<?php

namespace App\Services\Contratos;

use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\PrioridadTarea;
use App\Enums\ProcesoAprobacion;
use App\Enums\ResultadoEvaluacion;
use App\Enums\TipoBaja;
use App\Enums\TipoTarea;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\CicloLaboral\AprobacionService;
use App\Services\CierreLaboral\CierreLaboralService;
use App\Services\Colaboradores\BuscadorColaboradoresService;
use App\Services\Evaluaciones\FormularioEvaluacionService;
use App\Services\Tareas\NotificadorRhService;
use App\Services\Tareas\TareaService;
use App\Support\FechaMexico;
use App\Support\Paginacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Evaluación del periodo de prueba:
 *
 *   pendiente (scheduler) → capturada (jefe inmediato) → autorizada (RH/Dirección)
 *                                       ↘ devuelta (RH pide corrección) ↗
 *
 * Al autorizar:
 *  - renovar = sí → ContratoLaboralService::renovar(): contrato indeterminado y
 *    su documento entra al flujo firma → impresión → firma física → envío →
 *    recepción → archivo.
 *  - renovar = no → CierreLaboralService::iniciar() con tipo "no renovación".
 */
class EvaluacionPeriodoPruebaService
{
    public const PERMISO_AUTORIZAR = 'evaluaciones.autorizar';

    public const DECISION_RENOVAR = 'renovar';

    public const DECISION_NO_RENOVAR = 'no_renovar';

    /**
     * Pestañas del tablero que listan evaluaciones: en proceso = esperando
     * al jefe o por decidir RH; evaluadas = ya tienen respuestas (capturada
     * o autorizada).
     */
    public const PESTANA_EN_PROCESO = 'en_proceso';

    public const PESTANA_EVALUADAS = 'evaluadas';

    /** Sub-filtros (chips) dentro de una pestaña del tablero. */
    public const FILTRO_ESPERANDO_JEFE = 'esperando_jefe';

    public const FILTRO_POR_DECIDIR = 'por_decidir';

    public const FILTRO_AUTORIZADAS = 'autorizadas';

    public function __construct(
        private readonly AprobacionService $aprobaciones,
        private readonly ContratoLaboralService $contratos,
        private readonly CierreLaboralService $cierres,
        private readonly TareaService $tareas,
        private readonly NotificadorRhService $notificador,
        private readonly AuditoriaService $auditoria,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly FormularioEvaluacionService $formularios,
    ) {}

    /**
     * Captura oficial FO-RH-EPP-001 (version 2): criterios[{clave, calificacion
     * 1-4}] (9, fijos), filtros[{clave, respuesta bool}] (5, fijos),
     * observaciones (comentario del jefe; obligatorio si hay un 1/2 en
     * criterios o un Sí en filtros), fecha_evaluacion? (validado por
     * CapturarEvaluacionRequest). `recomienda_renovar`/`resultado` NUNCA se
     * aceptan del cliente: se calculan aquí con la regla canónica.
     *
     * @param  array<string, mixed>  $datos
     */
    public function capturar(EvaluacionPeriodoPrueba $evaluacion, User $evaluador, array $datos): EvaluacionPeriodoPrueba
    {
        $evaluacion = DB::transaction(function () use ($evaluacion, $evaluador, $datos): EvaluacionPeriodoPrueba {
            $evaluacion = EvaluacionPeriodoPrueba::query()->lockForUpdate()->findOrFail($evaluacion->id);

            if (! $evaluacion->estado->permiteCaptura()) {
                throw ValidationException::withMessages(['estado' => "La evaluación ya está «{$evaluacion->estado->etiqueta()}»."]);
            }

            // Versión del formulario con la que se captura (la de la
            // evaluación, o la vigente si aún no tiene): criterios, filtros y
            // reglas salen de ella, nunca del cliente.
            $definicion = $this->formularios->definicionPeriodoPrueba($evaluacion);
            [$criterios, $filtros] = $this->normalizarCapturaOficial($definicion, (array) $datos['criterios'], (array) $datos['filtros']);

            $total = array_sum(array_column($criterios, 'calificacion'));
            $tieneUno = in_array(1, array_column($criterios, 'calificacion'), true);
            $tieneSi = in_array(true, array_column($filtros, 'respuesta'), true);
            $comentarioHasta = (int) $definicion['comentario_obligatorio_hasta'];
            $requiereComentario = array_filter($criterios, fn (array $c): bool => $c['calificacion'] <= $comentarioHasta) !== []
                || ($tieneSi && (bool) $definicion['comentario_si_filtro']);

            $comentario = isset($datos['observaciones']) ? trim((string) $datos['observaciones']) : '';

            if ($requiereComentario && $comentario === '') {
                throw ValidationException::withMessages(['observaciones' => $comentarioHasta > 0
                    ? sprintf('El comentario del jefe es obligatorio: hay un criterio calificado con %s, o una pregunta filtro marcada "Sí".', $comentarioHasta === 1 ? '1' : sprintf('%d o menos', $comentarioHasta))
                    : 'El comentario del jefe es obligatorio: hay una pregunta filtro marcada "Sí".']);
            }

            // Regla canónica (FO-RH-EPP-001, Paso 3 — ver config
            // contratos.evaluacion_periodo_prueba_v2 para la inconsistencia
            // documentada del PDF): ningún 1, ningún filtro en Sí, total >= mínimo.
            $minimoRenovar = (float) $definicion['total_minimo_renovar'];
            $renueva = ! ($tieneUno && (bool) $definicion['un_uno_no_renueva']) && ! $tieneSi && $total >= $minimoRenovar;

            $evaluacion->update([
                'version' => (int) config('contratos.evaluacion_periodo_prueba_v2.version_vigente', 2),
                'formulario_evaluacion_id' => $definicion['formulario_id'],
                'criterios' => $criterios,
                'filtros' => $filtros,
                'calificacion' => $total,
                'resultado' => $renueva ? ResultadoEvaluacion::Aprobado : ResultadoEvaluacion::NoAprobado,
                'recomienda_renovar' => $renueva,
                'observaciones' => $comentario !== '' ? $comentario : null,
                // Fecha del día en México (la app corre en UTC: después de las
                // 18:00 now() ya es "mañana").
                'fecha_evaluacion' => $datos['fecha_evaluacion'] ?? now(VencimientoContratosService::ZONA)->toDateString(),
                'capturada_por' => $evaluador->id,
                'capturada_en' => now(),
                'estado' => EstadoEvaluacionPrueba::Capturada,
            ]);

            return $evaluacion;
        });

        $evaluacion->loadMissing('colaborador');

        // La recomendación del jefe es la PREAUTORIZACIÓN operativa: nunca
        // genera por sí sola contrato definitivo ni baja.
        $decision = $evaluacion->recomienda_renovar ? self::DECISION_RENOVAR : self::DECISION_NO_RENOVAR;

        if ($this->aprobaciones->pendiente($evaluacion, ProcesoAprobacion::PeriodoPrueba) !== null) {
            $this->aprobaciones->preautorizar($evaluacion, ProcesoAprobacion::PeriodoPrueba, $evaluador, $evaluacion->observaciones, $decision);
        } else {
            $this->aprobaciones->abrir($evaluacion, ProcesoAprobacion::PeriodoPrueba, [
                'colaborador' => $evaluacion->colaborador,
                'solicitante' => $evaluador,
                'aprobador_colaborador' => $evaluador->colaborador,
                'aprobador_user' => $evaluador,
                'implicita' => true,
                'decision' => $decision,
                'comentario' => sprintf('Recomendación del evaluador: %s.', $evaluacion->recomienda_renovar ? 'renovar' : 'no renovar'),
            ]);
        }

        $this->tareas->resolver(TipoTarea::EvaluacionPendiente, $evaluacion, $evaluador);
        $this->tareas->abrir(TipoTarea::EvaluacionPorAutorizar, $evaluacion, [
            'titulo' => "Autorizar evaluación: {$evaluacion->colaborador->nombreCompleto()}",
            'descripcion' => $evaluacion->recomienda_renovar ? 'El jefe recomienda renovar.' : 'El jefe recomienda NO renovar.',
            'prioridad' => PrioridadTarea::Alta,
            'colaborador' => $evaluacion->colaborador,
            'permiso' => self::PERMISO_AUTORIZAR,
            'accion' => 'autorizar_evaluacion',
        ]);

        $this->notificador->notificarEvento(
            'evaluacion_capturada',
            $evaluacion->colaborador,
            ['creador' => $evaluador, 'excluir' => [$evaluador->id]],
            'Evaluación de periodo de prueba por autorizar',
            "Se capturó la evaluación de {$evaluacion->colaborador->nombreCompleto()}.",
            $evaluacion,
            'autorizar_evaluacion',
        );

        $this->auditoria->registrar('evaluacion_capturada', $evaluacion, $evaluador, [
            'calificacion' => $evaluacion->calificacion,
            'resultado' => $evaluacion->resultado?->value,
            'recomienda_renovar' => $evaluacion->recomienda_renovar,
        ]);

        return $evaluacion->refresh();
    }

    /**
     * Valida y normaliza la captura oficial contra el catálogo real
     * (config contratos.evaluacion_periodo_prueba_v2): exige exactamente los
     * 9 criterios y las 5 preguntas filtro configuradas, sin faltantes ni
     * repetidos — nunca acepta una clave inventada por el cliente.
     *
     * @param  array<string, mixed>  $definicion  FormularioEvaluacionService::definicionPeriodoPrueba()
     * @param  array<int, mixed>  $criteriosEntrada
     * @param  array<int, mixed>  $filtrosEntrada
     * @return array{0: list<array{clave: string, etiqueta: string, calificacion: int}>, 1: list<array{clave: string, etiqueta: string, respuesta: bool}>}
     */
    private function normalizarCapturaOficial(array $definicion, array $criteriosEntrada, array $filtrosEntrada): array
    {
        /** @var list<array{clave: string, etiqueta: string}> $catalogoCriterios */
        $catalogoCriterios = $definicion['criterios'];
        /** @var list<array{clave: string, etiqueta: string}> $catalogoFiltros */
        $catalogoFiltros = $definicion['filtros'];

        $calificacionPorClave = collect($criteriosEntrada)
            ->filter(fn ($c) => is_array($c))
            ->mapWithKeys(fn (array $c) => [(string) ($c['clave'] ?? '') => $c['calificacion'] ?? null]);
        $respuestaPorClave = collect($filtrosEntrada)
            ->filter(fn ($f) => is_array($f))
            ->mapWithKeys(fn (array $f) => [(string) ($f['clave'] ?? '') => $f['respuesta'] ?? null]);

        $criterios = [];

        foreach ($catalogoCriterios as $oficial) {
            $calificacion = $calificacionPorClave->get($oficial['clave']);

            if (! is_numeric($calificacion) || (int) $calificacion < 1 || (int) $calificacion > 4) {
                throw ValidationException::withMessages(["criterios.{$oficial['clave']}" => "Falta calificar «{$oficial['etiqueta']}» (escala 1 a 4)."]);
            }

            $criterios[] = ['clave' => $oficial['clave'], 'etiqueta' => $oficial['etiqueta'], 'calificacion' => (int) $calificacion];
        }

        $filtros = [];

        foreach ($catalogoFiltros as $oficial) {
            // Mismos valores que acepta la regla `boolean` del FormRequest
            // (true/false, 1/0, "1"/"0"): antes 1/0 pasaban la validación y
            // aquí se rechazaban como "Falta responder".
            $respuesta = match ($respuestaPorClave->get($oficial['clave'])) {
                true, 1, '1' => true,
                false, 0, '0' => false,
                default => null,
            };

            if ($respuesta === null) {
                throw ValidationException::withMessages(["filtros.{$oficial['clave']}" => "Falta responder «{$oficial['etiqueta']}»."]);
            }

            $filtros[] = ['clave' => $oficial['clave'], 'etiqueta' => $oficial['etiqueta'], 'respuesta' => $respuesta];
        }

        return [$criterios, $filtros];
    }

    public function devolver(EvaluacionPeriodoPrueba $evaluacion, User $actor, string $motivo): EvaluacionPeriodoPrueba
    {
        $evaluacion = DB::transaction(function () use ($evaluacion, $motivo, $actor): EvaluacionPeriodoPrueba {
            $evaluacion = EvaluacionPeriodoPrueba::query()->lockForUpdate()->findOrFail($evaluacion->id);

            if ($evaluacion->estado !== EstadoEvaluacionPrueba::Capturada) {
                throw ValidationException::withMessages(['estado' => 'Solo una evaluación capturada puede devolverse.']);
            }

            $this->aprobaciones->devolver($evaluacion, ProcesoAprobacion::PeriodoPrueba, $actor, $motivo);
            $evaluacion->update(['estado' => EstadoEvaluacionPrueba::Devuelta, 'comentario_autorizacion' => $motivo]);

            return $evaluacion;
        });

        $evaluacion->loadMissing(['colaborador', 'evaluador.user']);
        $this->tareas->resolver(TipoTarea::EvaluacionPorAutorizar, $evaluacion, $actor);
        $evaluadorUsuario = $evaluacion->evaluador?->user;

        $this->tareas->abrir(TipoTarea::EvaluacionPendiente, $evaluacion, [
            'titulo' => "Corregir evaluación: {$evaluacion->colaborador->nombreCompleto()}",
            'descripcion' => $motivo,
            'prioridad' => PrioridadTarea::Alta,
            'colaborador' => $evaluacion->colaborador,
            'usuario' => $evaluadorUsuario,
            'permiso' => $evaluadorUsuario === null ? self::PERMISO_AUTORIZAR : null,
            'accion' => 'capturar_evaluacion',
        ]);

        $this->notificador->notificarEvento('evaluacion_devuelta', $evaluacion->colaborador, ['evaluador' => $evaluadorUsuario, 'excluir' => [$actor->id]], 'Evaluación devuelta', $motivo, $evaluacion, 'capturar_evaluacion');

        $this->auditoria->registrar('evaluacion_devuelta', $evaluacion, $actor, ['motivo' => $motivo]);

        return $evaluacion->refresh();
    }

    /**
     * @param  array<string, mixed>  $datos  renovar, comentario?, motivo_no_renovacion?, fecha_efectiva? (validado por AutorizarEvaluacionRequest).
     */
    public function autorizar(EvaluacionPeriodoPrueba $evaluacion, User $actor, array $datos): EvaluacionPeriodoPrueba
    {
        $renovar = (bool) $datos['renovar'];

        $evaluacion = DB::transaction(function () use ($evaluacion, $actor, $datos, $renovar): EvaluacionPeriodoPrueba {
            $evaluacion = EvaluacionPeriodoPrueba::query()->lockForUpdate()->findOrFail($evaluacion->id);

            if ($evaluacion->estado !== EstadoEvaluacionPrueba::Capturada) {
                throw ValidationException::withMessages(['estado' => 'Solo una evaluación capturada puede autorizarse.']);
            }

            // RH puede cambiar la recomendación del jefe, siempre con comentario.
            $comentario = isset($datos['comentario']) ? trim((string) $datos['comentario']) : '';

            if ($evaluacion->recomienda_renovar !== null && $evaluacion->recomienda_renovar !== $renovar && $comentario === '') {
                throw ValidationException::withMessages(['comentario' => 'Explica por qué RH decide distinto a la recomendación del jefe.']);
            }

            // Regla canónica (FO-RH-EPP-001): si la evaluación oficial no
            // alcanza la renovación, RH no puede "forzar aprobado" — solo
            // una excepción YA APROBADA por Dirección General lo permite.
            if ($renovar && (int) ($evaluacion->version ?? 1) >= 2 && $evaluacion->recomienda_renovar === false && $evaluacion->excepcionAprobada() === null) {
                throw ValidationException::withMessages(['renovar' => 'Esta evaluación no alcanza la regla de renovación del FO-RH-EPP-001. Solo Dirección General puede autorizar una excepción.']);
            }

            $this->aprobaciones->autorizarRh(
                $evaluacion,
                ProcesoAprobacion::PeriodoPrueba,
                $actor,
                $comentario !== '' ? $comentario : null,
                $renovar ? self::DECISION_RENOVAR : self::DECISION_NO_RENOVAR,
            );

            $evaluacion->update([
                'estado' => EstadoEvaluacionPrueba::Autorizada,
                'autorizada_por' => $actor->id,
                'autorizada_en' => now(),
                'decision_renovar' => $renovar,
                'comentario_autorizacion' => $datos['comentario'] ?? null,
            ]);

            return $evaluacion;
        });

        $evaluacion->loadMissing(['contrato', 'colaborador']);
        $this->auditoria->registrar('evaluacion_autorizada', $evaluacion, $actor, ['renovar' => $renovar]);
        $this->tareas->resolver(TipoTarea::EvaluacionPorAutorizar, $evaluacion, $actor);
        $this->tareas->resolver(TipoTarea::ContratoPorVencer, $evaluacion->contrato, $actor);

        if ($renovar) {
            $nuevo = $this->contratos->renovar($evaluacion->contrato, $actor);
            $evaluacion->update(['contrato_renovacion_id' => $nuevo->id]);
        } else {
            $contrato = $evaluacion->contrato;
            $fecha = isset($datos['fecha_efectiva']) ? (string) $datos['fecha_efectiva'] : ($contrato->fecha_fin?->toDateString() ?? FechaMexico::hoy());

            // La no renovación ya tiene preautorización (jefe) y autorización
            // RH: el cierre nace autorizado, genera sus documentos y la baja
            // NO se ejecuta antes de la fecha efectiva.
            $this->cierres->iniciarDesdeEvaluacion($evaluacion->colaborador, [
                // No renovación = fin de contrato con la causal «No acredita
                // periodo de prueba» (CierreLaboralService::iniciarDesdeEvaluacion).
                'tipo_baja' => TipoBaja::FinContrato->value,
                'motivo' => isset($datos['motivo_no_renovacion']) ? (string) $datos['motivo_no_renovacion'] : 'No renovación de contrato tras evaluación de periodo de prueba.',
                'fecha_efectiva' => $fecha,
            ], $actor, $evaluacion);
        }

        return $evaluacion->refresh();
    }

    /**
     * Evaluaciones visibles: RH/Dirección por alcance; un jefe solo las de su
     * equipo (donde es evaluador o jefe inmediato).
     *
     * @param  array<string, mixed>  $filtros  estado?, per_page?
     * @return LengthAwarePaginator<int, EvaluacionPeriodoPrueba>
     */
    public function listar(User $usuario, array $filtros = []): LengthAwarePaginator
    {
        $estado = isset($filtros['estado']) ? (string) $filtros['estado'] : null;

        return $this->queryVisible($usuario)
            ->when($estado, fn (Builder $q, string $v) => $q->where('estado', $v))
            ->orderBy('fecha_limite')
            ->paginate(Paginacion::porPagina(20, $filtros['per_page'] ?? null));
    }

    /**
     * Tablero «Evaluaciones de periodo de prueba» (pestañas En proceso /
     * Evaluadas; «Próximas / por asignar» sale de PeriodosPruebaService).
     *
     * @param  array<string, mixed>  $filtros  busqueda?, sucursal_id?, subfiltro?, per_page?
     * @return LengthAwarePaginator<int, EvaluacionPeriodoPrueba>
     */
    public function listarTablero(User $usuario, string $pestana, array $filtros = []): LengthAwarePaginator
    {
        $estados = $this->estadosDe($pestana, isset($filtros['subfiltro']) ? (string) $filtros['subfiltro'] : '');
        $query = $this->filtrarTablero($this->queryVisible($usuario), $filtros)
            ->with(['evaluador:id,name,apellidos', 'capturadaPor', 'autorizadaPor'])
            ->whereIn('estado', $estados);

        $query = $pestana === self::PESTANA_EVALUADAS
            ? $query->orderByDesc('capturada_en')->orderByDesc('id')
            : $query->orderBy('fecha_limite')->orderBy('id');

        return $query->paginate(Paginacion::porPagina(30, $filtros['per_page'] ?? null))->withQueryString();
    }

    /**
     * Conteos del tablero (tarjetas y pestañas) con los mismos filtros de
     * búsqueda y sucursal y el mismo alcance que el listado.
     *
     * @param  array<string, mixed>  $filtros
     * @return array{esperando_jefe: int, por_decidir: int, en_proceso: int, evaluadas: int, autorizadas: int, evaluadas_mes: int}
     */
    public function conteosTablero(User $usuario, array $filtros = []): array
    {
        $porEstado = $this->filtrarTablero($this->queryVisible($usuario), $filtros)
            ->toBase()
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado')
            ->map(fn ($total): int => (int) $total);
        $cuenta = fn (EstadoEvaluacionPrueba ...$estados): int => (int) collect($estados)->sum(fn (EstadoEvaluacionPrueba $e): int => (int) $porEstado->get($e->value, 0));
        $inicioMes = FechaMexico::inicioDeHoy()->startOfMonth()->toDateString();

        return [
            'esperando_jefe' => $cuenta(EstadoEvaluacionPrueba::Pendiente, EstadoEvaluacionPrueba::Devuelta),
            'por_decidir' => $cuenta(EstadoEvaluacionPrueba::Capturada),
            'en_proceso' => $cuenta(EstadoEvaluacionPrueba::Pendiente, EstadoEvaluacionPrueba::Devuelta, EstadoEvaluacionPrueba::Capturada),
            'evaluadas' => $cuenta(EstadoEvaluacionPrueba::Capturada, EstadoEvaluacionPrueba::Autorizada),
            'autorizadas' => $cuenta(EstadoEvaluacionPrueba::Autorizada),
            'evaluadas_mes' => $this->filtrarTablero($this->queryVisible($usuario), $filtros)
                ->whereIn('estado', [EstadoEvaluacionPrueba::Capturada->value, EstadoEvaluacionPrueba::Autorizada->value])
                ->whereDate('capturada_en', '>=', $inicioMes)
                ->count(),
        ];
    }

    /**
     * Respuestas de una evaluación tal como se GUARDARON (criterios y filtros
     * con su etiqueta de ese momento: si el formulario cambia después, lo
     * ya contestado no se reescribe). Alimenta «Ver respuestas» y el PDF
     * para firma.
     *
     * @return array{es_oficial: bool, criterios: list<array{clave: string|null, etiqueta: string, calificacion: float|int|null, comentario: string|null}>, filtros: list<array{clave: string|null, etiqueta: string, respuesta: bool}>, escala_max: int, total: float|null, total_maximo: int|null, total_minimo_renovar: float|null, motivos_no_renueva: list<string>, recomienda_renovar: bool|null, resultado: string|null, resultado_etiqueta: string|null, observaciones: string|null, evaluador: string|null, capturada_por: string|null, capturada_en: string|null, fecha_evaluacion: string|null, fecha_limite: string|null, decision: array{renovar: bool|null, comentario: string|null, autorizada_por: string|null, autorizada_en: string|null}}
     */
    public function respuestas(EvaluacionPeriodoPrueba $evaluacion): array
    {
        $evaluacion->loadMissing(['evaluador', 'capturadaPor', 'autorizadaPor']);
        $esOficial = (int) ($evaluacion->version ?? 1) >= 2;
        $escalaMax = $esOficial ? 4 : 10;

        $criterios = [];

        // Oficial: {clave, etiqueta, calificacion 1-4}; legacy (formato
        // libre): {criterio, calificacion 0-10, comentario?}.
        foreach ($evaluacion->criterios ?? [] as $criterio) {
            $comentario = array_key_exists('comentario', $criterio) ? trim((string) $criterio['comentario']) : '';
            $criterios[] = [
                'clave' => array_key_exists('clave', $criterio) ? $criterio['clave'] : null,
                'etiqueta' => array_key_exists('etiqueta', $criterio) ? $criterio['etiqueta'] : $criterio['criterio'],
                'calificacion' => $criterio['calificacion'],
                'comentario' => $comentario !== '' ? $comentario : null,
            ];
        }

        $filtros = [];

        foreach ($evaluacion->filtros ?? [] as $filtro) {
            $filtros[] = [
                'clave' => $filtro['clave'],
                'etiqueta' => $filtro['etiqueta'],
                'respuesta' => $filtro['respuesta'],
            ];
        }

        $total = $evaluacion->calificacion !== null ? (float) $evaluacion->calificacion : null;
        // Reglas de la versión del formulario con la que se capturó.
        $definicion = $this->formularios->definicionPeriodoPrueba($evaluacion);
        $minimo = $esOficial ? (float) $definicion['total_minimo_renovar'] : null;
        $motivos = [];

        if ($esOficial && $total !== null) {
            if ((bool) $definicion['un_uno_no_renueva'] && in_array(1, array_map(fn (array $c): int => (int) $c['calificacion'], $criterios), true)) {
                $motivos[] = 'Tiene al menos un 1 en el Paso 1';
            }

            if (in_array(true, array_column($filtros, 'respuesta'), true)) {
                $motivos[] = 'Tiene al menos un SÍ en el Paso 2';
            }

            if ($minimo !== null && $total < $minimo) {
                $motivos[] = sprintf('Su total es menor de %s puntos', rtrim(rtrim(number_format($minimo, 2), '0'), '.'));
            }
        }

        return [
            'es_oficial' => $esOficial,
            'criterios' => $criterios,
            'filtros' => $filtros,
            'escala_max' => $escalaMax,
            'total' => $total,
            'total_maximo' => $esOficial ? count($criterios) * $escalaMax : null,
            'total_minimo_renovar' => $minimo,
            'motivos_no_renueva' => $motivos,
            'recomienda_renovar' => $evaluacion->recomienda_renovar,
            'resultado' => $evaluacion->resultado?->value,
            'resultado_etiqueta' => $evaluacion->resultado?->etiqueta(),
            'observaciones' => $evaluacion->observaciones,
            'evaluador' => $evaluacion->evaluador?->nombreCompleto(),
            'capturada_por' => $evaluacion->capturadaPor?->nombreCompleto(),
            'capturada_en' => $evaluacion->capturada_en?->toIso8601String(),
            'fecha_evaluacion' => $evaluacion->fecha_evaluacion?->toDateString(),
            'fecha_limite' => $evaluacion->fecha_limite?->toDateString(),
            'decision' => [
                'renovar' => $evaluacion->decision_renovar,
                'comentario' => $evaluacion->comentario_autorizacion,
                'autorizada_por' => $evaluacion->autorizadaPor?->nombreCompleto(),
                'autorizada_en' => $evaluacion->autorizada_en?->toIso8601String(),
            ],
        ];
    }

    /** Solo una evaluación con respuestas (capturada o autorizada) se imprime para firma. */
    public function tieneRespuestas(EvaluacionPeriodoPrueba $evaluacion): bool
    {
        return in_array($evaluacion->estado, [EstadoEvaluacionPrueba::Capturada, EstadoEvaluacionPrueba::Autorizada], true);
    }

    /**
     * Evaluaciones visibles: RH/Dirección por alcance del módulo; un jefe
     * solo las de su equipo (donde es evaluador o jefe inmediato).
     *
     * @return Builder<EvaluacionPeriodoPrueba>
     */
    private function queryVisible(User $usuario): Builder
    {
        $query = EvaluacionPeriodoPrueba::query()->with([
            'colaborador:id,name,apellidos,numero_empleado,jefe_id,sucursal_principal_id,puesto_id,fecha_ingreso',
            'colaborador.puesto:id,nombre', 'colaborador.sucursalPrincipal:id,nombre', 'colaborador.jefe:id,name,apellidos',
            'contrato.documento', 'contrato.evaluacion', 'solicitudesExcepcion',
        ]);

        // Quien no autoriza ve las que evalúa o de su equipo; con lectura
        // global (p. ej. Sistemas: evaluaciones.ver_todos) las consulta todas
        // sin poder capturar ni autorizar (lo decide la Policy).
        if (! $usuario->can(self::PERMISO_AUTORIZAR) && ! $usuario->can('evaluaciones.ver_todos')) {
            $query->where(fn (Builder $q) => $q
                ->where('evaluador_colaborador_id', $usuario->colaborador_id ?? 0)
                ->orWhereHas('colaborador', fn (Builder $c) => $c->where('jefe_id', $usuario->colaborador_id ?? 0)));
        } else {
            // Alcance del módulo «evaluaciones» (Roles y permisos), no por nombre de rol.
            $this->alcance->limitarPorModulo($query, $usuario, 'evaluaciones', 'colaborador_id', false);
        }

        return $query;
    }

    /**
     * @param  Builder<EvaluacionPeriodoPrueba>  $query
     * @param  array<string, mixed>  $filtros  busqueda?, sucursal_id?
     * @return Builder<EvaluacionPeriodoPrueba>
     */
    private function filtrarTablero(Builder $query, array $filtros): Builder
    {
        $busqueda = isset($filtros['busqueda']) ? trim((string) $filtros['busqueda']) : '';
        $sucursalId = isset($filtros['sucursal_id']) ? (int) $filtros['sucursal_id'] : 0;

        return $query
            ->when($busqueda !== '', fn (Builder $q) => app(BuscadorColaboradoresService::class)->aplicarPorColumna($q, $busqueda))
            ->when($sucursalId > 0, fn (Builder $q) => $q->whereHas('colaborador', fn (Builder $c) => $c->where('sucursal_principal_id', $sucursalId)));
    }

    /**
     * @return list<string>
     */
    private function estadosDe(string $pestana, string $subfiltro): array
    {
        $estados = match (true) {
            $pestana === self::PESTANA_EVALUADAS && $subfiltro === self::FILTRO_POR_DECIDIR => [EstadoEvaluacionPrueba::Capturada],
            $pestana === self::PESTANA_EVALUADAS && $subfiltro === self::FILTRO_AUTORIZADAS => [EstadoEvaluacionPrueba::Autorizada],
            $pestana === self::PESTANA_EVALUADAS => [EstadoEvaluacionPrueba::Capturada, EstadoEvaluacionPrueba::Autorizada],
            $subfiltro === self::FILTRO_ESPERANDO_JEFE => [EstadoEvaluacionPrueba::Pendiente, EstadoEvaluacionPrueba::Devuelta],
            $subfiltro === self::FILTRO_POR_DECIDIR => [EstadoEvaluacionPrueba::Capturada],
            default => [EstadoEvaluacionPrueba::Pendiente, EstadoEvaluacionPrueba::Devuelta, EstadoEvaluacionPrueba::Capturada],
        };

        return array_map(fn (EstadoEvaluacionPrueba $e): string => $e->value, $estados);
    }

    /**
     * @return array<string, mixed>
     */
    public function aArray(EvaluacionPeriodoPrueba $evaluacion): array
    {
        $esOficial = (int) ($evaluacion->version ?? 1) >= 2;
        $excepcion = $evaluacion->excepcionAprobada() ?? $evaluacion->solicitudesExcepcion->last();
        // Versión del formulario de ESTA evaluación (o la vigente si aún no se captura).
        $definicion = $this->formularios->definicionPeriodoPrueba($evaluacion);

        return [
            'id' => $evaluacion->id,
            'colaborador' => [
                'id' => $evaluacion->colaborador->id,
                'nombre' => $evaluacion->colaborador->nombreCompleto(),
                'numero_empleado' => $evaluacion->colaborador->numero_empleado,
                'puesto' => $evaluacion->colaborador->puesto?->nombre,
                'sucursal' => $evaluacion->colaborador->sucursalPrincipal?->nombre,
                'jefe' => $evaluacion->colaborador->jefe?->nombreCompleto(),
                'fecha_ingreso' => $evaluacion->colaborador->fecha_ingreso?->toDateString(),
            ],
            'contrato' => $this->contratos->aArray($evaluacion->contrato),
            'estado' => $evaluacion->estado->value,
            'estado_etiqueta' => $evaluacion->estado->etiqueta(),
            'fecha_limite' => $evaluacion->fecha_limite?->toDateString(),
            'fecha_evaluacion' => $evaluacion->fecha_evaluacion?->toDateString(),
            'version' => $evaluacion->version,
            'es_oficial' => $esOficial,
            // Legacy (version null/1): lista libre ya capturada. Oficial
            // (version >= 2): los 9 ítems {clave, etiqueta, calificacion}.
            'criterios' => $evaluacion->criterios ?? [],
            'filtros' => $evaluacion->filtros ?? [],
            'criterios_oficiales' => $definicion['criterios'],
            'filtros_oficiales' => $definicion['filtros'],
            'total_minimo_renovar' => (float) $definicion['total_minimo_renovar'],
            'total_maximo' => (int) $definicion['total_maximo'],
            // Título, diseño, escala y reglas para dibujar el formulario de captura.
            'formulario' => $this->formularios->contenidoPeriodoPrueba($definicion),
            'criterios_sugeridos' => config('contratos.criterios_evaluacion', []),
            'calificacion' => $evaluacion->calificacion,
            'resultado' => $evaluacion->resultado?->value,
            'recomienda_renovar' => $evaluacion->recomienda_renovar,
            'observaciones' => $evaluacion->observaciones,
            'decision_renovar' => $evaluacion->decision_renovar,
            'comentario_autorizacion' => $evaluacion->comentario_autorizacion,
            'autorizada_en' => $evaluacion->autorizada_en?->toIso8601String(),
            'contrato_renovacion_id' => $evaluacion->contrato_renovacion_id,
            'excepcion' => $excepcion !== null ? [
                'id' => $excepcion->id,
                'estado' => $excepcion->estado->value,
                'estado_etiqueta' => $excepcion->estado->etiqueta(),
                'motivo' => $excepcion->motivo,
                'comentario_resolucion' => $excepcion->comentario_resolucion,
                'resuelto_en' => $excepcion->resuelto_en?->toIso8601String(),
            ] : null,
        ];
    }
}
