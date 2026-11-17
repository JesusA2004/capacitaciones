<?php

namespace App\Services\Colaboradores;

use App\Enums\EstadoContratoLaboral;
use App\Enums\EstadoEvaluacionInduccion;
use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\EstadoEvaluacionTecnica;
use App\Enums\EstadoUsuario;
use App\Enums\TipoContratacion;
use App\Enums\UnidadPeriodoPrueba;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionInduccion;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\EvaluacionTecnica;
use App\Models\PasoEvaluacionTecnica;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Calendario\CalendarioLaboralService;
use App\Services\Contratos\ContratoLaboralService;
use App\Support\FechaMexico;
use App\Support\Paginacion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * ÚNICA fuente de las fechas laborales de un colaborador y de lo que se
 * calcula con ellas (docs/FECHAS_LABORALES.md). La consultan el periodo de
 * prueba (PeriodosPruebaService, ElegibilidadEvaluacionPeriodoPruebaService),
 * los Seguimientos técnicos (ProgramacionEvaluacionTecnicaService y la línea
 * de tiempo), la regularización de contratos y la sección «Fechas laborales»
 * del expediente.
 *
 * Reglas:
 *  - Fecha de ingreso (colaboradores.fecha_ingreso) = inicio de labores.
 *    Antigüedad, vacaciones, rotación y plantilla la siguen usando tal cual.
 *  - Inicio de capacitación inicial (colaboradores.periodo_prueba_inicio) =
 *    desde aquí corren los días del periodo de prueba y de los Seguimientos.
 *    Capacitación inicial y periodo de prueba son la MISMA etapa
 *    (TipoContratacion::normalizada). Vacía = la fecha de ingreso.
 *  - Periodo de prueba efectivo = el ESPECÍFICO de la persona
 *    (periodo_prueba_cantidad + periodo_prueba_unidad) o, si no hay, el del
 *    puesto (puestos.meses_periodo_prueba). Nunca uno inventado.
 *  - Fin del periodo = inicio + periodo − 1 día (meses: sin desbordar fin de mes).
 *  - Evaluación de periodo de prueba habilitada desde: el primer día en que
 *    faltan contratos.dias_aviso_vencimiento días HÁBILES (15) o menos para
 *    el fin (mismo criterio que el scheduler).
 *  - Seguimiento N = inicio de capacitación inicial + N días.
 *
 * @phpstan-type Periodo array{cantidad: int|null, unidad: string|null, origen: string, etiqueta: string, puesto_meses: int|null, puesto_etiqueta: string|null}
 * @phpstan-type Alerta array{codigo: string, nivel: string, titulo: string, detalle: string, accion: string|null, accion_etiqueta: string|null, contrato_id: int|null, url: string|null}
 */
class FechasLaboralesService
{
    public const PERMISO_EDITAR = 'expedientes.editar_fechas';

    /** Campos que se capturan en «Fechas laborales» (dominio de correcciones fechas_laborales). */
    public const CAMPOS = ['fecha_ingreso', 'periodo_prueba_inicio', 'fecha_induccion', 'fecha_alta_imss', 'periodo_prueba_cantidad', 'periodo_prueba_unidad'];

    public function __construct(
        private readonly CalendarioLaboralService $calendario,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    // ───────────────────────── Reglas de cálculo ─────────────────────────

    /**
     * @return Periodo
     */
    public function periodoEfectivo(Colaborador $colaborador): array
    {
        $colaborador->loadMissing('puesto');
        $mesesPuesto = (int) ($colaborador->puesto->meses_periodo_prueba ?? 0);
        $puestoEtiqueta = $mesesPuesto >= 1 ? UnidadPeriodoPrueba::Meses->describir($mesesPuesto) : null;
        $cantidad = (int) ($colaborador->periodo_prueba_cantidad ?? 0);
        $unidad = $colaborador->periodo_prueba_unidad;

        if ($cantidad >= 1 && $unidad instanceof UnidadPeriodoPrueba) {
            return [
                'cantidad' => $cantidad,
                'unidad' => $unidad->value,
                'origen' => 'especifico',
                'etiqueta' => $unidad->describir($cantidad),
                'puesto_meses' => $mesesPuesto >= 1 ? $mesesPuesto : null,
                'puesto_etiqueta' => $puestoEtiqueta,
            ];
        }

        if ($mesesPuesto >= 1) {
            return [
                'cantidad' => $mesesPuesto,
                'unidad' => UnidadPeriodoPrueba::Meses->value,
                'origen' => 'puesto',
                'etiqueta' => (string) $puestoEtiqueta,
                'puesto_meses' => $mesesPuesto,
                'puesto_etiqueta' => $puestoEtiqueta,
            ];
        }

        return [
            'cantidad' => null,
            'unidad' => null,
            'origen' => 'sin_definir',
            'etiqueta' => 'Sin definir',
            'puesto_meses' => null,
            'puesto_etiqueta' => null,
        ];
    }

    /** Desde aquí corren los días: inicio de capacitación inicial o, vacío, la fecha de ingreso. */
    public function inicioCapacitacion(Colaborador $colaborador): ?CarbonImmutable
    {
        $fecha = $colaborador->periodo_prueba_inicio ?? $colaborador->fecha_ingreso;

        return $fecha !== null ? CarbonImmutable::parse($fecha->toDateString()) : null;
    }

    /** Último día del periodo de prueba (= fin de la capacitación inicial). */
    public function finPeriodoPrueba(Colaborador $colaborador): ?CarbonImmutable
    {
        $inicio = $this->inicioCapacitacion($colaborador);

        return $inicio !== null ? $this->finDesde($colaborador, $inicio) : null;
    }

    /** Fin del periodo efectivo contado desde $inicio; null si no hay periodo. */
    public function finDesde(Colaborador $colaborador, CarbonInterface $inicio): ?CarbonImmutable
    {
        $periodo = $this->periodoEfectivo($colaborador);
        $inicio = CarbonImmutable::parse($inicio->toDateString());

        if ($periodo['cantidad'] === null) {
            return null;
        }

        return $periodo['unidad'] === UnidadPeriodoPrueba::Dias->value
            ? $inicio->addDays($periodo['cantidad'] - 1)
            : $inicio->addMonthsNoOverflow($periodo['cantidad'])->subDay();
    }

    /** Base de los Seguimientos técnicos (día N = base + N días). */
    public function baseSeguimientos(Colaborador $colaborador): ?CarbonImmutable
    {
        return $this->inicioCapacitacion($colaborador);
    }

    /**
     * Primer día en que ya se puede asignar la evaluación de periodo de
     * prueba: el más antiguo desde el que faltan N días hábiles o menos para
     * $fin (fin <= día + N hábiles), igual que el scheduler.
     */
    public function evaluacionHabilitadaDesde(CarbonInterface $fin): CarbonImmutable
    {
        $fin = CarbonImmutable::parse($fin->toDateString());
        $dias = $this->diasAnticipacion();
        $desde = $fin;

        // Tope de seguridad: N días hábiles nunca abarcan más de 3N + 7 naturales.
        for ($i = 0; $i < $dias * 3 + 7; $i++) {
            $anterior = $desde->subDay();

            if ($this->calendario->sumarDiasHabiles(Carbon::parse($anterior->toDateString()), $dias)->toDateString() < $fin->toDateString()) {
                break;
            }

            $desde = $anterior;
        }

        return $desde;
    }

    public function diasAnticipacion(): int
    {
        return max(0, (int) config('contratos.dias_aviso_vencimiento', 15));
    }

    /**
     * Fecha de inducción: la capturada por RH o, si no, la de su evaluación
     * de inducción aprobada en PEOPLE.
     *
     * @return array{fecha: string|null, origen: string}
     */
    public function fechaInduccion(Colaborador $colaborador): array
    {
        if ($colaborador->fecha_induccion !== null) {
            return ['fecha' => $colaborador->fecha_induccion->toDateString(), 'origen' => 'capturada'];
        }

        $aprobada = EvaluacionInduccion::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('estado', EstadoEvaluacionInduccion::Aprobada->value)
            ->whereNotNull('aprobada_en')
            ->orderByDesc('aprobada_en')
            ->value('aprobada_en');

        return $aprobada !== null
            ? ['fecha' => CarbonImmutable::parse((string) $aprobada)->toDateString(), 'origen' => 'sistema']
            : ['fecha' => null, 'origen' => 'falta'];
    }

    /**
     * Fechas calculadas, en orden, para comparar antes/después.
     *
     * @return array<string, array{etiqueta: string, fecha: string|null}>
     */
    public function calculadas(Colaborador $colaborador): array
    {
        $fin = $this->finPeriodoPrueba($colaborador);
        $base = $this->baseSeguimientos($colaborador);
        $fechas = [
            'inicio_capacitacion' => ['etiqueta' => 'Inicio de capacitación inicial (corren los días)', 'fecha' => $this->inicioCapacitacion($colaborador)?->toDateString()],
            'evaluacion_desde' => ['etiqueta' => 'Se habilita la evaluación de periodo de prueba', 'fecha' => $fin !== null ? $this->evaluacionHabilitadaDesde($fin)->toDateString() : null],
            'fin_periodo' => ['etiqueta' => 'Fin de capacitación inicial / periodo de prueba', 'fecha' => $fin?->toDateString()],
        ];

        foreach ($this->pasosSeguimiento($colaborador) as $paso) {
            $fechas[sprintf('seguimiento_%d', $paso->dias)] = [
                'etiqueta' => $paso->nombre,
                'fecha' => $base?->addDays($paso->dias)->toDateString(),
            ];
        }

        return $fechas;
    }

    // ───────────────────────── Resumen para el expediente ─────────────────────────

    /**
     * Todo lo que muestra la sección «Fechas laborales» del expediente.
     *
     * @return array<string, mixed>
     */
    public function resumen(Colaborador $colaborador, User $viewer, ?CarbonInterface $hoy = null): array
    {
        $hoy = CarbonImmutable::parse(($hoy ?? FechaMexico::inicioDeHoy())->toDateString());
        $colaborador->loadMissing('puesto');
        $periodo = $this->periodoEfectivo($colaborador);
        $inicio = $this->inicioCapacitacion($colaborador);
        $fin = $this->finPeriodoPrueba($colaborador);
        $desde = $fin !== null ? $this->evaluacionHabilitadaDesde($fin) : null;
        $induccion = $this->fechaInduccion($colaborador);
        $contrato = $this->contratoVigente($colaborador);
        $evaluacion = EvaluacionPeriodoPrueba::query()->where('colaborador_id', $colaborador->id)->orderByDesc('id')->first();

        return [
            'colaborador_id' => $colaborador->id,
            'puede_editar' => $this->puedeEditar($viewer, $colaborador),
            'hoy' => $hoy->toDateString(),
            'capturadas' => [
                'fecha_ingreso' => $colaborador->fecha_ingreso?->toDateString(),
                'periodo_prueba_inicio' => $colaborador->periodo_prueba_inicio?->toDateString(),
                'fecha_induccion' => $colaborador->fecha_induccion?->toDateString(),
                'fecha_alta_imss' => $colaborador->fecha_alta_imss?->toDateString(),
                'periodo_prueba_cantidad' => $colaborador->periodo_prueba_cantidad,
                'periodo_prueba_unidad' => $colaborador->periodo_prueba_unidad?->value,
            ],
            'periodo' => $periodo,
            'induccion' => $induccion,
            'inicio_capacitacion' => $inicio?->toDateString(),
            'inicio_capacitacion_origen' => $colaborador->periodo_prueba_inicio !== null ? 'capturada' : ($colaborador->fecha_ingreso !== null ? 'ingreso' : 'falta'),
            'fin_periodo' => $fin?->toDateString(),
            'evaluacion_desde' => $desde?->toDateString(),
            'dias_transcurridos' => $inicio !== null ? (int) $inicio->diffInDays($hoy, false) : null,
            'dias_restantes' => $fin !== null ? (int) $hoy->diffInDays($fin, false) : null,
            'contrato' => $contrato !== null ? $this->contratoArray($contrato) : null,
            'evaluacion_periodo_prueba' => $evaluacion !== null ? [
                'id' => $evaluacion->id,
                'estado' => $evaluacion->estado->value,
                'estado_etiqueta' => $evaluacion->estado->etiqueta(),
                'fecha_limite' => $evaluacion->fecha_limite?->toDateString(),
            ] : null,
            'seguimientos' => $this->seguimientos($colaborador, $hoy),
            'faltantes' => $this->faltantes($colaborador),
            'alertas' => $this->alertas($colaborador, $contrato),
            'importado' => $colaborador->candidato_id === null,
        ];
    }

    /**
     * Qué falta capturar (nunca se inventa una fecha).
     *
     * @return list<array{campo: string, etiqueta: string, critico: bool}>
     */
    public function faltantes(Colaborador $colaborador): array
    {
        $faltan = [];

        if ($colaborador->fecha_ingreso === null) {
            $faltan[] = ['campo' => 'fecha_ingreso', 'etiqueta' => 'Fecha de ingreso', 'critico' => true];
        }

        if ($this->periodoEfectivo($colaborador)['origen'] === 'sin_definir') {
            $faltan[] = ['campo' => 'periodo_prueba_cantidad', 'etiqueta' => 'Periodo de prueba (ni específico ni del puesto)', 'critico' => true];
        }

        if ($this->fechaInduccion($colaborador)['fecha'] === null) {
            $faltan[] = ['campo' => 'fecha_induccion', 'etiqueta' => 'Fecha de inducción', 'critico' => false];
        }

        if ($colaborador->fecha_alta_imss === null) {
            $faltan[] = ['campo' => 'fecha_alta_imss', 'etiqueta' => 'Fecha de alta IMSS', 'critico' => false];
        }

        return $faltan;
    }

    /**
     * Avisos de lo que quedó desfasado: «Debes actualizar el contrato», etc.
     * Se calculan siempre con los datos vigentes: desaparecen solos al
     * corregir la causa.
     *
     * @return list<Alerta>
     */
    public function alertas(Colaborador $colaborador, ?ContratoLaboral $contrato = null): array
    {
        $contrato ??= $this->contratoVigente($colaborador);
        $inicio = $this->inicioCapacitacion($colaborador);
        $fin = $this->finPeriodoPrueba($colaborador);
        $alertas = [];
        $expediente = sprintf('/rh/expedientes/%d?tab=contratacion', $colaborador->id);

        if ($contrato !== null && $contrato->tipo->normalizada() === TipoContratacion::CapacitacionInicial) {
            $firmado = $this->contratoFirmado($contrato);
            $difInicio = $inicio !== null && $contrato->fecha_inicio->toDateString() !== $inicio->toDateString();
            $difFin = $fin !== null && $contrato->fecha_fin?->toDateString() !== $fin->toDateString();

            if ($difInicio || $difFin) {
                $partes = [];

                if ($difInicio) {
                    $partes[] = sprintf('inicia el %s y la capacitación inicial el %s', $contrato->fecha_inicio->format('d/m/Y'), $inicio->format('d/m/Y'));
                }

                if ($difFin) {
                    $partes[] = sprintf('termina el %s y el periodo de prueba el %s', $contrato->fecha_fin?->format('d/m/Y') ?? 'sin fecha', $fin->format('d/m/Y'));
                }

                $alertas[] = $firmado ? [
                    'codigo' => 'contrato_firmado_desfasado',
                    'nivel' => 'critico',
                    'titulo' => 'La fecha impresa en el contrato firmado ya no coincide: genera uno nuevo',
                    'detalle' => sprintf('El contrato de capacitación inicial firmado %s. Un contrato firmado no se modifica en silencio: requiere modificación contractual (nueva versión) y volver a firmar.', implode(' y ', $partes)),
                    'accion' => 'corregir_contrato',
                    'accion_etiqueta' => 'Ver opciones del contrato',
                    'contrato_id' => $contrato->id,
                    'url' => $expediente,
                ] : [
                    'codigo' => 'contrato_desfasado',
                    'nivel' => 'alto',
                    'titulo' => 'Debes actualizar el contrato',
                    'detalle' => sprintf('El contrato de capacitación inicial %s. Corrige sus fechas y vuelve a generar el documento para que imprima las nuevas.', implode(' y ', $partes)),
                    'accion' => 'corregir_contrato',
                    'accion_etiqueta' => 'Actualizar contrato',
                    'contrato_id' => $contrato->id,
                    'url' => $expediente,
                ];
            }

            $evaluacion = EvaluacionPeriodoPrueba::query()->where('contrato_laboral_id', $contrato->id)->first();

            if ($evaluacion !== null && $fin !== null && in_array($evaluacion->estado, [EstadoEvaluacionPrueba::Pendiente, EstadoEvaluacionPrueba::Devuelta], true)
                && $evaluacion->fecha_limite?->toDateString() !== $fin->toDateString()) {
                $alertas[] = [
                    'codigo' => 'evaluacion_desfasada',
                    'nivel' => 'medio',
                    'titulo' => 'La evaluación de periodo de prueba tiene otra fecha límite',
                    'detalle' => sprintf('Vence el %s y el periodo de prueba termina el %s. Se ajusta al guardar las fechas.', $evaluacion->fecha_limite?->format('d/m/Y') ?? 'sin fecha', $fin->format('d/m/Y')),
                    'accion' => null,
                    'accion_etiqueta' => null,
                    'contrato_id' => $contrato->id,
                    'url' => null,
                ];
            }
        }

        $indeterminado = $contrato?->tipo === TipoContratacion::Indeterminado || $colaborador->contrato_indeterminado_fisico_desde !== null;
        $hoy = CarbonImmutable::parse(FechaMexico::inicioDeHoy()->toDateString());

        if ($contrato === null && ! $indeterminado && $fin !== null && $fin->gte($hoy) && $this->sigueLaborando($colaborador)) {
            $alertas[] = [
                'codigo' => 'sin_contrato',
                'nivel' => 'medio',
                'titulo' => 'Sin contrato registrado en PEOPLE',
                'detalle' => sprintf('Sigue en periodo de prueba (termina el %s) pero no tiene contrato vigente en el sistema. Regístralo en Contratos (toma estas fechas) para poder asignarle su evaluación de periodo de prueba.', $fin->format('d/m/Y')),
                'accion' => 'ir_contratos',
                'accion_etiqueta' => 'Ir a Contratos',
                'contrato_id' => null,
                'url' => sprintf('/rh/contratos?busqueda=%s', rawurlencode($colaborador->nombreCompleto())),
            ];
        }

        $sinPeriodo = $this->periodoEfectivo($colaborador)['origen'] === 'sin_definir';

        if ($colaborador->fecha_ingreso === null || $sinPeriodo) {
            $alertas[] = [
                'codigo' => 'faltan_fechas',
                'nivel' => 'medio',
                'titulo' => 'Faltan fechas',
                'detalle' => $colaborador->fecha_ingreso === null
                    ? 'Sin fecha de ingreso no se calcula su periodo de prueba, su evaluación ni sus Seguimientos.'
                    : 'Ni la persona ni su puesto tienen periodo de prueba: captura uno específico o configúralo en el puesto.',
                'accion' => 'editar_fechas',
                'accion_etiqueta' => 'Capturar fechas',
                'contrato_id' => null,
                'url' => null,
            ];
        }

        return $alertas;
    }

    /**
     * Seguimientos técnicos del puesto con su fecha según la base vigente y,
     * si ya se generaron, su estado.
     *
     * @return list<array<string, mixed>>
     */
    public function seguimientos(Colaborador $colaborador, CarbonImmutable $hoy): array
    {
        $base = $this->baseSeguimientos($colaborador);
        $generadas = EvaluacionTecnica::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('puesto_id', $colaborador->puesto_id)
            ->get()
            ->keyBy('dias');
        $lista = [];

        foreach ($this->pasosSeguimiento($colaborador) as $paso) {
            $evaluacion = $generadas->get($paso->dias);
            $fecha = $evaluacion !== null ? CarbonImmutable::parse($evaluacion->fecha_programada->toDateString()) : $base?->addDays($paso->dias);
            $lista[] = [
                'dias' => $paso->dias,
                'nombre' => $paso->nombre,
                'es_final' => $paso->es_final,
                'fecha' => $fecha?->toDateString(),
                'fecha_calculada' => $base?->addDays($paso->dias)->toDateString(),
                'evaluacion_id' => $evaluacion?->id,
                'estado' => $evaluacion !== null ? $evaluacion->estado->value : ($fecha === null ? 'sin_fecha' : ($fecha->lt($hoy) ? 'no_generada' : 'programada')),
                'estado_etiqueta' => $evaluacion !== null ? $evaluacion->estado->etiqueta() : ($fecha === null ? 'Faltan fechas' : ($fecha->lt($hoy) ? 'No se generó' : 'Programada')),
            ];
        }

        return $lista;
    }

    // ───────────────────────── Impacto (vista previa) ─────────────────────────

    /**
     * El colaborador EN MEMORIA con los valores nuevos (no guarda nada).
     *
     * @param  array<string, string|null>  $valores
     */
    public function simular(Colaborador $colaborador, array $valores): Colaborador
    {
        $copia = clone $colaborador;

        foreach (array_intersect_key($valores, array_flip(self::CAMPOS)) as $campo => $valor) {
            $copia->setAttribute($campo, $valor === '' ? null : $valor);
        }

        return $copia;
    }

    /**
     * Fechas calculadas que cambian (antes/después legibles).
     *
     * @param  array<string, string|null>  $valores
     * @return list<array{campo: string, etiqueta: string, antes: string|null, despues: string|null, confidencial: bool}>
     */
    public function cambiosCalculados(Colaborador $colaborador, array $valores): array
    {
        $antes = $this->calculadas($colaborador);
        $despues = $this->calculadas($this->simular($colaborador, $valores));
        $periodoAntes = $this->periodoEfectivo($colaborador);
        $periodoDespues = $this->periodoEfectivo($this->simular($colaborador, $valores));
        $lista = [];

        if ($periodoAntes['etiqueta'] !== $periodoDespues['etiqueta'] || $periodoAntes['origen'] !== $periodoDespues['origen']) {
            $lista[] = [
                'campo' => 'periodo_efectivo',
                'etiqueta' => 'Periodo de prueba que se aplica',
                'antes' => $this->periodoLegible($periodoAntes),
                'despues' => $this->periodoLegible($periodoDespues),
                'confidencial' => false,
            ];
        }

        foreach ($despues as $clave => $dato) {
            $previo = $antes[$clave]['fecha'] ?? null;

            if ($previo !== $dato['fecha']) {
                $lista[] = [
                    'campo' => $clave,
                    'etiqueta' => $dato['etiqueta'],
                    'antes' => $this->legible($previo),
                    'despues' => $this->legible($dato['fecha']),
                    'confidencial' => false,
                ];
            }
        }

        return $lista;
    }

    /**
     * Registros que toca o que quedan desfasados con el cambio.
     *
     * @param  array<string, string|null>  $valores
     * @return list<array{tipo: string, etiqueta: string, detalle: string, accion: string}>
     */
    public function afectados(Colaborador $colaborador, array $valores): array
    {
        $simulado = $this->simular($colaborador, $valores);
        $afectados = [];

        foreach ($this->alertas($simulado) as $alerta) {
            if (! in_array($alerta['codigo'], ['contrato_desfasado', 'contrato_firmado_desfasado', 'sin_contrato'], true)) {
                continue;
            }

            $afectados[] = [
                'tipo' => 'contrato',
                'etiqueta' => $alerta['titulo'],
                'detalle' => $alerta['detalle'],
                'accion' => $alerta['codigo'] === 'contrato_firmado_desfasado' ? 'requiere_modificacion_contractual' : 'revisar',
            ];
        }

        $pendientes = $this->evaluacionesPorRecalcular($colaborador, $simulado);

        if ($pendientes['tecnicas'] > 0) {
            $afectados[] = ['tipo' => 'evaluacion', 'etiqueta' => sprintf('%d Seguimiento(s) técnico(s) pendiente(s)', $pendientes['tecnicas']), 'detalle' => 'Se reprograman con la nueva fecha base. Los ya contestados no se tocan.', 'accion' => 'se_actualiza'];
        }

        if ($pendientes['periodo_prueba'] > 0) {
            $afectados[] = ['tipo' => 'evaluacion', 'etiqueta' => 'Evaluación de periodo de prueba pendiente', 'detalle' => 'Su fecha límite pasa al nuevo fin del periodo de prueba. Si ya se contestó, no se toca.', 'accion' => 'se_actualiza'];
        }

        if (array_key_exists('fecha_ingreso', $valores)) {
            $afectados[] = ['tipo' => 'antiguedad', 'etiqueta' => 'Antigüedad, vacaciones y rotación', 'detalle' => 'Se calculan con la fecha de ingreso: cambian con ella (la rotación del mes de ingreso también).', 'accion' => 'se_actualiza'];
        }

        $contestadas = EvaluacionTecnica::query()->where('colaborador_id', $colaborador->id)->where('estado', EstadoEvaluacionTecnica::Contestada->value)->count();

        if ($contestadas > 0) {
            $afectados[] = ['tipo' => 'evaluacion', 'etiqueta' => sprintf('%d Seguimiento(s) ya contestado(s)', $contestadas), 'detalle' => 'Conservan su fecha y su resultado.', 'accion' => 'intacto'];
        }

        return $afectados;
    }

    /**
     * Guarda las fechas capturadas (ya validadas por el dominio de
     * correcciones, dentro de SU transacción) y recalcula lo pendiente:
     * periodo_prueba_fin del colaborador, Seguimientos técnicos pendientes y
     * la fecha límite de la evaluación de periodo de prueba no contestada.
     * Nunca toca un contrato ni una evaluación contestada.
     *
     * @param  array<string, string|null>  $valores  Sin fecha_ingreso (va por DatosExpedienteService).
     * @return array{tecnicas: int, periodo_prueba: int}
     */
    public function guardar(Colaborador $colaborador, array $valores): array
    {
        $cambios = [];

        foreach (array_intersect_key($valores, array_flip(self::CAMPOS)) as $campo => $valor) {
            $cambios[$campo] = $valor === '' ? null : $valor;
        }

        if ($cambios !== []) {
            $colaborador->update($cambios);
        }

        return $this->recalcularPendientes($colaborador->refresh());
    }

    /**
     * @return array{tecnicas: int, periodo_prueba: int}
     */
    public function recalcularPendientes(Colaborador $colaborador): array
    {
        $fin = $this->finPeriodoPrueba($colaborador);
        $base = $this->baseSeguimientos($colaborador);
        $tecnicas = 0;
        $periodo = 0;

        // Cache del fin en el colaborador (reportes y variables de formatos la leen).
        if ($fin !== null && $colaborador->periodo_prueba_fin?->toDateString() !== $fin->toDateString()
            && ($colaborador->periodo_prueba_fin !== null || $colaborador->periodo_prueba_inicio !== null || $colaborador->periodo_prueba_cantidad !== null)) {
            $colaborador->update(['periodo_prueba_fin' => $fin->toDateString()]);
        }

        if ($base !== null) {
            foreach ($this->tecnicasPendientes($colaborador) as $evaluacion) {
                $programada = $base->addDays($evaluacion->dias);

                if ($evaluacion->fecha_programada->toDateString() === $programada->toDateString()) {
                    continue;
                }

                $plazo = (int) CarbonImmutable::parse($evaluacion->fecha_programada->toDateString())->diffInDays(CarbonImmutable::parse($evaluacion->fecha_limite->toDateString()), false);
                $evaluacion->update([
                    'fecha_programada' => $programada->toDateString(),
                    'fecha_limite' => $programada->addDays(max(0, $plazo))->toDateString(),
                ]);
                $tecnicas++;
            }
        }

        if ($fin !== null) {
            foreach ($this->periodoPruebaPendientes($colaborador) as $evaluacion) {
                if ($evaluacion->fecha_limite?->toDateString() === $fin->toDateString()) {
                    continue;
                }

                $evaluacion->update(['fecha_limite' => $fin->toDateString()]);
                $periodo++;
            }
        }

        return ['tecnicas' => $tecnicas, 'periodo_prueba' => $periodo];
    }

    // ───────────────────────── Captura masiva ─────────────────────────

    /**
     * Activos del alcance con sus fechas (por defecto, solo a quien le faltan).
     *
     * @param  array<string, mixed>  $filtros  busqueda, sucursal_id, puesto_id, solo_faltantes
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function listadoCaptura(User $usuario, array $filtros): LengthAwarePaginator
    {
        $busqueda = trim((string) ($filtros['busqueda'] ?? ''));
        $sucursalId = (int) ($filtros['sucursal_id'] ?? 0);
        $puestoId = (int) ($filtros['puesto_id'] ?? 0);
        $soloFaltantes = filter_var($filtros['solo_faltantes'] ?? true, FILTER_VALIDATE_BOOLEAN);

        $query = Colaborador::query()
            ->whereIn('estatus', [EstadoUsuario::Activo->value, EstadoUsuario::EnIncorporacion->value])
            ->with(['puesto:id,nombre,meses_periodo_prueba', 'sucursalPrincipal:id,nombre'])
            ->when($busqueda !== '', fn (Builder $q) => $q->buscar($busqueda))
            ->when($sucursalId > 0, fn (Builder $q) => $q->where('sucursal_principal_id', $sucursalId))
            ->when($puestoId > 0, fn (Builder $q) => $q->where('puesto_id', $puestoId))
            ->when($soloFaltantes, fn (Builder $q) => $this->conFaltantes($q))
            ->orderBy('name')
            ->orderBy('apellidos');

        $this->alcance->limitarPorModulo($query, $usuario, 'expedientes', 'id');

        $paginador = $query->paginate(Paginacion::porPagina())->withQueryString();
        $ids = $paginador->getCollection()->pluck('id')->all();
        $inducciones = EvaluacionInduccion::query()
            ->whereIn('colaborador_id', $ids)
            ->where('estado', EstadoEvaluacionInduccion::Aprobada->value)
            ->whereNotNull('aprobada_en')
            ->orderBy('aprobada_en')
            ->get(['colaborador_id', 'aprobada_en'])
            ->keyBy('colaborador_id');

        // Paginador NUEVO (setCollection conserva el tipo genérico original).
        return new LengthAwarePaginator(
            $paginador->getCollection()->map(fn (Colaborador $c): array => $this->filaCaptura($c, $inducciones->get($c->id)?->aprobada_en?->toDateString()))->values(),
            $paginador->total(),
            $paginador->perPage(),
            $paginador->currentPage(),
            ['path' => $paginador->path(), 'query' => request()->query()],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function filaCaptura(Colaborador $c, ?string $sistema): array
    {
        $periodo = $this->periodoEfectivo($c);
        $fin = $this->finPeriodoPrueba($c);
        $faltan = [];

        if ($c->fecha_ingreso === null) {
            $faltan[] = 'Ingreso';
        }

        if ($periodo['origen'] === 'sin_definir') {
            $faltan[] = 'Periodo de prueba';
        }

        if ($c->fecha_induccion === null && $sistema === null) {
            $faltan[] = 'Inducción';
        }

        if ($c->fecha_alta_imss === null) {
            $faltan[] = 'Alta IMSS';
        }

        return [
            'id' => $c->id,
            'nombre' => $c->nombreCompleto(),
            'numero_empleado' => $c->numero_empleado,
            'puesto' => $c->puesto?->nombre,
            'sucursal' => $c->sucursalPrincipal?->nombre,
            'importado' => $c->candidato_id === null,
            'fecha_ingreso' => $c->fecha_ingreso?->toDateString(),
            'periodo_prueba_inicio' => $c->periodo_prueba_inicio?->toDateString(),
            'fecha_induccion' => $c->fecha_induccion?->toDateString(),
            'fecha_induccion_sistema' => $sistema,
            'fecha_alta_imss' => $c->fecha_alta_imss?->toDateString(),
            'periodo_prueba_cantidad' => $c->periodo_prueba_cantidad,
            'periodo_prueba_unidad' => $c->periodo_prueba_unidad?->value,
            'periodo' => $periodo,
            'fin_periodo' => $fin?->toDateString(),
            'faltantes' => $faltan,
            'expediente_url' => sprintf('/rh/expedientes/%d?tab=fechas', $c->id),
        ];
    }

    /**
     * @param  Builder<Colaborador>  $query
     * @return Builder<Colaborador>
     */
    private function conFaltantes(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('fecha_ingreso')
            ->orWhereNull('fecha_alta_imss')
            ->orWhere(fn (Builder $p) => $p
                ->whereNull('periodo_prueba_cantidad')
                ->where(fn (Builder $sinPuesto) => $sinPuesto
                    ->whereNull('puesto_id')
                    ->orWhereHas('puesto', fn (Builder $pu) => $pu->whereNull('meses_periodo_prueba')->orWhere('meses_periodo_prueba', '<', 1))))
            ->orWhere(fn (Builder $i) => $i
                ->whereNull('fecha_induccion')
                ->whereNotExists(fn ($sub) => $sub->selectRaw('1')
                    ->from('evaluaciones_induccion')
                    ->whereColumn('evaluaciones_induccion.colaborador_id', 'colaboradores.id')
                    ->where('evaluaciones_induccion.estado', EstadoEvaluacionInduccion::Aprobada->value))));
    }

    public function puedeEditar(User $usuario, Colaborador $colaborador): bool
    {
        // Nadie edita sus propias fechas de RH (solo solicita corrección).
        return $usuario->colaborador_id !== $colaborador->id
            && $usuario->can(self::PERMISO_EDITAR)
            && $this->alcance->puedeVerExpediente($usuario, $colaborador);
    }

    // ───────────────────────── Apoyo ─────────────────────────

    public function contratoVigente(Colaborador $colaborador): ?ContratoLaboral
    {
        return ContratoLaboral::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('estado', EstadoContratoLaboral::Vigente->value)
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function contratoArray(ContratoLaboral $contrato): array
    {
        return [
            'id' => $contrato->id,
            'tipo' => $contrato->tipo->value,
            'tipo_etiqueta' => $contrato->tipo->etiqueta(),
            'fecha_inicio' => $contrato->fecha_inicio->toDateString(),
            'fecha_fin' => $contrato->fecha_fin?->toDateString(),
            'firmado' => $this->contratoFirmado($contrato),
            'documento_generado' => $contrato->generated_document_id !== null,
        ];
    }

    private function contratoFirmado(ContratoLaboral $contrato): bool
    {
        try {
            return app(ContratoLaboralService::class)->contratoFirmado($contrato);
        } catch (Throwable) {
            // Un documento ilegible no impide ver las fechas: se toma como sin firmar.
            return false;
        }
    }

    /**
     * @return Collection<int, PasoEvaluacionTecnica>
     */
    private function pasosSeguimiento(Colaborador $colaborador): Collection
    {
        if ($colaborador->puesto_id === null) {
            return new Collection;
        }

        return PasoEvaluacionTecnica::query()
            ->where('puesto_id', $colaborador->puesto_id)
            ->where('activo', true)
            ->orderBy('dias')
            ->get()
            ->toBase();
    }

    /**
     * @return Collection<int, EvaluacionTecnica>
     */
    private function tecnicasPendientes(Colaborador $colaborador): Collection
    {
        return EvaluacionTecnica::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('estado', EstadoEvaluacionTecnica::Pendiente->value)
            ->get()
            ->toBase();
    }

    /**
     * @return Collection<int, EvaluacionPeriodoPrueba>
     */
    private function periodoPruebaPendientes(Colaborador $colaborador): Collection
    {
        return EvaluacionPeriodoPrueba::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereIn('estado', [EstadoEvaluacionPrueba::Pendiente->value, EstadoEvaluacionPrueba::Devuelta->value])
            ->get()
            ->toBase();
    }

    /**
     * Cuántas evaluaciones pendientes cambiarían de fecha con el simulado.
     *
     * @return array{tecnicas: int, periodo_prueba: int}
     */
    private function evaluacionesPorRecalcular(Colaborador $actual, Colaborador $simulado): array
    {
        $base = $this->baseSeguimientos($simulado);
        $fin = $this->finPeriodoPrueba($simulado);

        return [
            'tecnicas' => $base === null ? 0 : $this->tecnicasPendientes($actual)
                ->filter(fn (EvaluacionTecnica $e): bool => $e->fecha_programada->toDateString() !== $base->addDays($e->dias)->toDateString())
                ->count(),
            'periodo_prueba' => $fin === null ? 0 : $this->periodoPruebaPendientes($actual)
                ->filter(fn (EvaluacionPeriodoPrueba $e): bool => $e->fecha_limite?->toDateString() !== $fin->toDateString())
                ->count(),
        ];
    }

    private function sigueLaborando(Colaborador $colaborador): bool
    {
        return in_array($colaborador->estatus, [EstadoUsuario::Activo, EstadoUsuario::EnIncorporacion], true);
    }

    /**
     * @param  Periodo  $periodo
     */
    private function periodoLegible(array $periodo): string
    {
        return match ($periodo['origen']) {
            'especifico' => sprintf('Específico: %s', $periodo['etiqueta']),
            'puesto' => sprintf('Hereda del puesto: %s', $periodo['etiqueta']),
            default => 'Sin definir',
        };
    }

    private function legible(?string $fecha): ?string
    {
        return $fecha !== null ? CarbonImmutable::parse($fecha)->format('d/m/Y') : null;
    }
}
