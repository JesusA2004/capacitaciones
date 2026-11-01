<?php

namespace App\Services\Nomina;

use App\Enums\EstadoReciboNomina;
use App\Enums\EstadoRectificacionNomina;
use App\Enums\EstadoSolicitudInterna;
use App\Enums\EstatusBaja;
use App\Enums\GocePermiso;
use App\Enums\ReglaDiasPagados;
use App\Enums\TipoConceptoNomina;
use App\Enums\TipoPermisoSolicitado;
use App\Enums\TipoSolicitudInterna;
use App\Models\Baja;
use App\Models\Colaborador;
use App\Models\Incapacidad;
use App\Models\NominaRectificacion;
use App\Models\ReciboNomina;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Compensacion\CompensacionService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Fuente ÚNICA de incidencias de nómina: el recibo, la pantalla de
 * Incapacidades y el reporte «Incidencias de nómina» leen de aquí.
 *
 * Trabaja día por día: cada fecha del periodo recibe UNA sola clasificación
 * (fuera de vigencia > incapacidad > permiso sin goce > permiso con goce), así
 * un permiso y una incapacidad en el mismo día nunca descuentan dos veces.
 * Los días naturales de incidencia se reportan tal cual; los días salariales
 * pagados los decide la regla de config/nomina_incidencias.php, que RH debe
 * validar (ver ReglaDiasPagados).
 *
 * @phpstan-type Incidencia array{tipo: string, etiqueta: string, referencia: string, fecha_inicio: string, fecha_fin: string, dias_en_periodo: int, dias_con_efecto: int, goce: string, efecto: string}
 * @phpstan-type Resumen array{periodo_inicio: string, periodo_fin: string, dias_naturales: int, dias_base: int, dias_incapacidad: int, dias_sin_goce: int, dias_con_goce: int, dias_fuera_de_vigencia: int, dias_no_pagables: int, dias_pagados: float, regla: string, regla_validada: bool, incidencias: list<Incidencia>, avisos: list<string>}
 */
class IncidenciasNominaService
{
    private const PREFIJO_AVISO = 'Incidencias: ';

    public function __construct(
        private readonly ReciboNominaService $recibos,
        private readonly CompensacionService $compensacion,
        private readonly AuditoriaService $auditoria,
    ) {}

    public function regla(): ReglaDiasPagados
    {
        return ReglaDiasPagados::tryFrom((string) config('nomina_incidencias.regla')) ?? ReglaDiasPagados::DiasCalendarioConTope;
    }

    /**
     * Regla vigente, para mostrarla en pantalla con su estado de validación.
     *
     * @return array{clave: string, etiqueta: string, validada_por_rh: bool, aviso: string|null}
     */
    public function reglaParaPantalla(): array
    {
        $validada = (bool) config('nomina_incidencias.validada_por_rh', false);

        return [
            'clave' => $this->regla()->value,
            'etiqueta' => $this->regla()->etiqueta(),
            'validada_por_rh' => $validada,
            'aviso' => $validada ? null : 'Regla de días pendiente de validar por Recursos Humanos: revisa los importes antes de emitir.',
        ];
    }

    /**
     * Resumen de incidencias de una persona en un periodo.
     *
     * @return Resumen
     */
    public function resumen(Colaborador $colaborador, CarbonInterface $inicio, CarbonInterface $fin, ?int $diasBase = null): array
    {
        $desde = CarbonImmutable::parse($inicio->toDateString());
        $hasta = CarbonImmutable::parse($fin->toDateString());
        $naturales = (int) $desde->diffInDays($hasta) + 1;
        $base = $diasBase ?? $naturales;
        $ultimoDia = $this->ultimoDiaLaborado($colaborador);

        // Una sola clasificación por día: el orden de los pasos es la prioridad.
        $dias = [];

        for ($dia = $desde; $dia->lte($hasta); $dia = $dia->addDay()) {
            $fecha = $dia->toDateString();
            $fuera = ($colaborador->fecha_ingreso !== null && $fecha < $colaborador->fecha_ingreso->toDateString())
                || ($ultimoDia !== null && $fecha > $ultimoDia->toDateString());
            $dias[$fecha] = $fuera ? 'fuera' : null;
        }

        $incidencias = [];

        foreach ($this->incapacidadesDe($colaborador->id, $desde, $hasta) as $incapacidad) {
            $pagada = in_array($incapacidad->tipo->value, (array) config('nomina_incidencias.incapacidad_pagada_por_empresa', []), true);
            $conEfecto = $this->marcar($dias, $incapacidad->fecha_inicio, $incapacidad->fecha_fin, $pagada ? 'con_goce' : 'incapacidad');
            $incidencias[] = [
                'tipo' => 'incapacidad',
                'etiqueta' => sprintf('Incapacidad · %s', $incapacidad->tipo->etiqueta()),
                'referencia' => $incapacidad->serie_folio_imss,
                'fecha_inicio' => $incapacidad->fecha_inicio->toDateString(),
                'fecha_fin' => $incapacidad->fecha_fin->toDateString(),
                'dias_en_periodo' => $this->diasEnPeriodo($incapacidad->fecha_inicio, $incapacidad->fecha_fin, $desde, $hasta),
                'dias_con_efecto' => $conEfecto,
                'goce' => 'IMSS',
                'efecto' => $pagada ? 'Pagado por la empresa' : sprintf('%d día(s) no pagados en el recibo', $conEfecto),
            ];
        }

        foreach ($this->permisosDe($colaborador->id, $desde, $hasta) as $permiso) {
            $inicioPermiso = $permiso->fecha_inicio ?? $desde;
            $finPermiso = $permiso->fecha_fin ?? $inicioPermiso;
            $goce = $this->gocePermiso($permiso);

            // Salir temprano / llegar tarde: se reporta, no descuenta un día completo.
            if ($this->esPermisoParcial($permiso)) {
                $incidencias[] = [
                    'tipo' => 'permiso_parcial',
                    'etiqueta' => $permiso->permiso_tipo === TipoPermisoSolicitado::LlegarTarde->value ? 'Permiso · Llegada tarde' : 'Permiso · Salida temprano',
                    'referencia' => (string) $permiso->folio,
                    'fecha_inicio' => $inicioPermiso->toDateString(),
                    'fecha_fin' => $finPermiso->toDateString(),
                    'dias_en_periodo' => 0,
                    'dias_con_efecto' => 0,
                    'goce' => $goce ? 'Con goce' : 'Sin goce',
                    'efecto' => $goce ? 'Sin efecto en días' : 'Parcial: RH decide el ajuste',
                ];

                continue;
            }

            $conEfecto = $this->marcar($dias, $inicioPermiso, $finPermiso, $goce ? 'con_goce' : 'sin_goce');
            $enPeriodo = $this->diasEnPeriodo($inicioPermiso, $finPermiso, $desde, $hasta);
            $incidencias[] = [
                'tipo' => $goce ? 'permiso_con_goce' : 'permiso_sin_goce',
                'etiqueta' => 'Permiso',
                'referencia' => (string) $permiso->folio,
                'fecha_inicio' => $inicioPermiso->toDateString(),
                'fecha_fin' => $finPermiso->toDateString(),
                'dias_en_periodo' => $enPeriodo,
                'dias_con_efecto' => $conEfecto,
                'goce' => $goce ? 'Con goce' : 'Sin goce',
                'efecto' => match (true) {
                    $goce => 'Sin descuento',
                    $conEfecto < $enPeriodo => sprintf('%d día(s) no pagados; %d ya contados en otra incidencia', $conEfecto, $enPeriodo - $conEfecto),
                    default => sprintf('%d día(s) no pagados en el recibo', $conEfecto),
                },
            ];
        }

        $conteo = array_count_values(array_filter($dias, fn (?string $v): bool => $v !== null));
        $fuera = $conteo['fuera'] ?? 0;
        $incapacidad = $conteo['incapacidad'] ?? 0;
        $sinGoce = $conteo['sin_goce'] ?? 0;
        $noPagables = $fuera + $incapacidad + $sinGoce;
        $pagados = $this->regla()->diasPagados($base, $naturales, $noPagables);

        $avisos = [];

        if ($incapacidad > 0 || $sinGoce > 0) {
            $avisos[] = sprintf('%s%d día(s) de incapacidad y %d de permiso sin goce en el periodo: se pagan %s de %d día(s) salariales.', self::PREFIJO_AVISO, $incapacidad, $sinGoce, $this->formatoDias($pagados), $base);
        }

        if ($ultimoDia !== null && $ultimoDia->lt($hasta)) {
            $avisos[] = sprintf('%sÚltimo día laborado %s: se pagan %s día(s).', self::PREFIJO_AVISO, $ultimoDia->format('d/m/Y'), $this->formatoDias($pagados));
        }

        return [
            'periodo_inicio' => $desde->toDateString(),
            'periodo_fin' => $hasta->toDateString(),
            'dias_naturales' => $naturales,
            'dias_base' => $base,
            'dias_incapacidad' => $incapacidad,
            'dias_sin_goce' => $sinGoce,
            'dias_con_goce' => $conteo['con_goce'] ?? 0,
            'dias_fuera_de_vigencia' => $fuera,
            'dias_no_pagables' => $noPagables,
            'dias_pagados' => $pagados,
            'regla' => $this->regla()->value,
            'regla_validada' => (bool) config('nomina_incidencias.validada_por_rh', false),
            'incidencias' => $incidencias,
            'avisos' => $avisos,
        ];
    }

    /**
     * Recalcula los recibos de la persona que tocan el rango tras un cambio en
     * sus incidencias. Un borrador se actualiza; uno emitido NUNCA cambia aquí:
     * deja una rectificación pendiente para RH. Idempotente: si los días ya
     * coinciden no toca nada (una edición manual del importe se conserva).
     */
    public function sincronizarRecibos(Colaborador $colaborador, CarbonInterface $desde, CarbonInterface $hasta, ?User $actor, string $motivo): void
    {
        $recibos = ReciboNomina::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereNull('rectificado_por_id')
            ->where('estado', '!=', EstadoReciboNomina::Cancelado->value)
            ->whereIn('tipo_periodo', [ReciboNominaService::TIPO_PERIODO_SEMANAL, ReciboNominaService::TIPO_PERIODO_QUINCENAL])
            ->whereDate('periodo_inicio', '<=', $hasta->toDateString())
            ->whereDate('periodo_fin', '>=', $desde->toDateString())
            ->orderBy('periodo_inicio')
            ->get();

        foreach ($recibos as $recibo) {
            $resumen = $this->resumen($colaborador, $recibo->periodo_inicio, $recibo->periodo_fin, $this->diasBaseDe($recibo));

            if ($recibo->estado === EstadoReciboNomina::Emitido) {
                $this->registrarRectificacion($recibo, $colaborador, $resumen, $motivo, $actor);

                continue;
            }

            if ($actor === null || $this->coincide($recibo, $resumen)) {
                continue;
            }

            try {
                $this->aplicar($recibo, $colaborador, $resumen, $actor);
            } catch (ValidationException $e) {
                $recibo->update(['advertencias' => $this->conAviso($recibo, sprintf('%sNo se pudo aplicar al borrador: %s', self::PREFIJO_AVISO, (string) collect($e->errors())->flatten()->first()))]);
            }
        }
    }

    /**
     * Tras corregir un sueldo desde $desde: los recibos preparados se ajustan
     * al sueldo vigente en su periodo; los emitidos NUNCA cambian y quedan con
     * una rectificación pendiente si el importe difiere.
     *
     * @return array{borradores: int, rectificaciones: int}
     */
    public function sincronizarSueldo(Colaborador $colaborador, string $desde, string $motivo, User $actor): array
    {
        $resultado = ['borradores' => 0, 'rectificaciones' => 0];

        foreach ($this->recibosDesde($colaborador, $desde) as $recibo) {
            $resumen = $this->resumen($colaborador, $recibo->periodo_inicio, $recibo->periodo_fin, $this->diasBaseDe($recibo));
            $importe = $this->importeSueldo($recibo, $colaborador, $resumen);

            if (abs($importe - (float) $recibo->sueldo_base) < 0.005 && $this->coincide($recibo, $resumen)) {
                continue;
            }

            if ($recibo->estado === EstadoReciboNomina::Emitido) {
                if ($this->registrarRectificacion($recibo, $colaborador, $resumen, $motivo, $actor, 'sueldo')) {
                    $resultado['rectificaciones']++;
                }

                continue;
            }

            try {
                $this->aplicar($recibo, $colaborador, $resumen, $actor);
                $resultado['borradores']++;
            } catch (ValidationException $e) {
                $recibo->update(['advertencias' => $this->conAviso($recibo, sprintf('%sNo se pudo aplicar el sueldo corregido: %s', self::PREFIJO_AVISO, (string) collect($e->errors())->flatten()->first()))]);
            }
        }

        return $resultado;
    }

    /**
     * Vista previa de sincronizarSueldo() con el mensual propuesto: no escribe.
     *
     * @return list<array{recibo: ReciboNomina, importe_actual: float, importe_nuevo: float}>
     */
    public function recibosAfectadosPorSueldo(Colaborador $colaborador, string $desde, float $mensual): array
    {
        $filas = [];

        foreach ($this->recibosDesde($colaborador, $desde) as $recibo) {
            $dias = (float) ($recibo->dias_pagados ?? $this->diasBaseDe($recibo));
            $importe = $recibo->nomina_lote_id === null
                ? $this->compensacion->proporcionalDesdeMensual($mensual, $dias)
                : round($this->compensacion->diarioDesdeMensual($mensual) * $dias, 2);

            if (abs($importe - (float) $recibo->sueldo_base) >= 0.005) {
                $filas[] = ['recibo' => $recibo, 'importe_actual' => (float) $recibo->sueldo_base, 'importe_nuevo' => $importe];
            }
        }

        return $filas;
    }

    /**
     * Rectificación capturada por RH sobre un recibo emitido: se guarda lo
     * propuesto, el recibo no cambia hasta aplicarla y emitir el nuevo.
     *
     * @param  list<array<string, mixed>>  $conceptos
     * @param  array{dias_pagados: float, dias_incapacidad: float, dias_falta: float, importe_sueldo: float}  $dias
     */
    public function proponerRectificacion(ReciboNomina $recibo, array $conceptos, array $dias, string $motivo, User $actor): NominaRectificacion
    {
        $this->recibos->exigirRectificable($recibo);

        $pendiente = NominaRectificacion::query()
            ->where('recibo_nomina_id', $recibo->id)
            ->where('estado', EstadoRectificacionNomina::Pendiente->value)
            ->lockForUpdate()
            ->first();

        $datos = [
            'colaborador_id' => $recibo->colaborador_id,
            'dias_pagados_antes' => $recibo->dias_pagados,
            'dias_pagados_despues' => $dias['dias_pagados'],
            'dias_incapacidad_despues' => $dias['dias_incapacidad'],
            'dias_falta_despues' => $dias['dias_falta'],
            'neto_antes' => $recibo->neto,
            'importe_sueldo_despues' => $dias['importe_sueldo'],
            'motivo' => $motivo,
            'origen' => 'manual',
            'conceptos_propuestos' => $conceptos,
        ];

        if ($pendiente !== null) {
            $pendiente->update($datos);
        } else {
            $pendiente = NominaRectificacion::query()->create(['recibo_nomina_id' => $recibo->id, 'estado' => EstadoRectificacionNomina::Pendiente, ...$datos]);
        }

        $this->auditoria->registrar('nomina_rectificacion_propuesta', $recibo, $actor, ['rectificacion_id' => $pendiente->id, 'motivo' => $motivo]);

        return $pendiente->refresh();
    }

    /**
     * RH aplica la rectificación: emite un recibo NUEVO enlazado con el sueldo
     * vigente EN EL PERIODO y los días de hoy (o lo capturado si fue manual).
     * El original conserva folio, conceptos y PDF. Si el PDF falla, sigue
     * pendiente y se puede reintentar.
     *
     * @throws ValidationException
     */
    public function aplicarRectificacion(NominaRectificacion $rectificacion, User $actor): NominaRectificacion
    {
        $this->exigirPendiente($rectificacion);
        $recibo = $rectificacion->recibo;
        $colaborador = $recibo->colaborador;

        if ($rectificacion->conceptos_propuestos !== null) {
            $conceptos = $rectificacion->conceptos_propuestos;
            $dias = [
                'dias_pagados' => (float) $rectificacion->dias_pagados_despues,
                'dias_incapacidad' => (float) $rectificacion->dias_incapacidad_despues,
                'dias_falta' => (float) $rectificacion->dias_falta_despues,
                'sueldo_base' => (float) $rectificacion->importe_sueldo_despues,
            ];
        } else {
            $resumen = $this->resumen($colaborador, $recibo->periodo_inicio, $recibo->periodo_fin, $this->diasBaseDe($recibo));

            $vigente = $this->compensacion->vigenteEn($colaborador, $recibo->periodo_fin);

            if ($vigente === null) {
                throw ValidationException::withMessages(['rectificacion' => sprintf('No hay sueldo registrado para el periodo %s – %s: revísalo antes de rectificar.', $recibo->periodo_inicio->format('d/m/Y'), $recibo->periodo_fin->format('d/m/Y'))]);
            }

            $conceptos = $this->recibos->conceptosEditables($recibo);
            $indice = $this->indiceConceptoSueldo($conceptos);

            if ($indice === null) {
                throw ValidationException::withMessages(['conceptos' => 'El recibo no tiene un concepto de sueldo identificable: captura la rectificación a mano.']);
            }

            $importe = $this->importeSueldo($recibo, $colaborador, $resumen);
            $conceptos[$indice]['importe'] = $importe;
            $conceptos[$indice]['cantidad'] = $resumen['dias_pagados'];
            $dias = [
                'dias_pagados' => $resumen['dias_pagados'],
                'dias_incapacidad' => $resumen['dias_incapacidad'],
                'dias_falta' => $resumen['dias_sin_goce'],
                'sueldo_base' => $importe,
                'advertencias' => $this->avisosDeSueldo($colaborador, $recibo, $vigente),
            ];
        }

        $nuevo = $this->recibos->emitirRectificacion($recibo, $conceptos, $dias, $rectificacion->motivo, $actor);

        // Si el préstamo no admitió la emisión, el nuevo sigue preparado.
        if ($nuevo->estado !== EstadoReciboNomina::Emitido) {
            throw ValidationException::withMessages(['rectificacion' => 'No se pudo emitir el recibo de la rectificación.']);
        }

        $rectificacion->update([
            'estado' => EstadoRectificacionNomina::Aplicada,
            'resuelta_por' => $actor->id,
            'resuelta_en' => now(),
            'recibo_resultante_id' => $nuevo->id,
        ]);
        $this->auditoria->registrar('nomina_rectificacion_aplicada', $recibo, $actor, ['rectificacion_id' => $rectificacion->id, 'dias_pagados' => $dias['dias_pagados'], 'recibo_resultante_id' => $nuevo->id]);

        return $rectificacion->refresh();
    }

    public function descartarRectificacion(NominaRectificacion $rectificacion, string $nota, User $actor): NominaRectificacion
    {
        $this->exigirPendiente($rectificacion);
        $rectificacion->update(['estado' => EstadoRectificacionNomina::Descartada, 'nota_resolucion' => $nota, 'resuelta_por' => $actor->id, 'resuelta_en' => now()]);
        $this->auditoria->registrar('nomina_rectificacion_descartada', $rectificacion->recibo, $actor, ['rectificacion_id' => $rectificacion->id, 'nota' => $nota]);

        return $rectificacion->refresh();
    }

    /**
     * Lo que RH debe revisar a mano en el sueldo con el que se rectifica: se
     * reporta en el recibo, no se adivina.
     *
     * @param  array{mensual: float, diario: float, fecha_efectiva: string|null, exacto: bool, fuente: string}  $vigente
     * @return list<string>
     */
    private function avisosDeSueldo(Colaborador $colaborador, ReciboNomina $recibo, array $vigente): array
    {
        $avisos = [];

        if (! $vigente['exacto']) {
            $avisos[] = $vigente['fuente'] === 'sin_historial'
                ? 'Sin historial de sueldo para este periodo: se usó el sueldo vigente. Revísalo.'
                : 'El historial de este periodo solo guarda el diario: el mensual se calculó como diario × 30. Revísalo.';
        }

        $alInicio = $this->compensacion->vigenteEn($colaborador, $recibo->periodo_inicio);

        if ($alInicio === null || abs($alInicio['mensual'] - $vigente['mensual']) >= 0.005) {
            $avisos[] = 'El sueldo cambió dentro del periodo: se usó el vigente al cierre, sin prorratear. Revísalo.';
        }

        return $avisos;
    }

    /**
     * Recibos no cancelados ni ya rectificados cuyo periodo termina en $desde
     * o después.
     *
     * @return Collection<int, ReciboNomina>
     */
    private function recibosDesde(Colaborador $colaborador, string $desde): Collection
    {
        return ReciboNomina::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereNull('rectificado_por_id')
            ->where('estado', '!=', EstadoReciboNomina::Cancelado->value)
            ->whereIn('tipo_periodo', [ReciboNominaService::TIPO_PERIODO_SEMANAL, ReciboNominaService::TIPO_PERIODO_QUINCENAL])
            ->whereDate('periodo_fin', '>=', $desde)
            ->orderBy('periodo_inicio')
            ->get();
    }

    /**
     * Base salarial del recibo: 15 en quincena, 7 en semana.
     */
    public function diasBaseDe(ReciboNomina $recibo): int
    {
        return $recibo->tipo_periodo === ReciboNominaService::TIPO_PERIODO_SEMANAL ? 7 : 15;
    }

    /**
     * @return Collection<int, Incapacidad>
     */
    public function incapacidadesDe(int $colaboradorId, CarbonInterface $desde, CarbonInterface $hasta): Collection
    {
        return Incapacidad::query()
            ->where('colaborador_id', $colaboradorId)
            ->whereNull('cancelada_en')
            ->whereDate('fecha_inicio', '<=', $hasta->toDateString())
            ->whereDate('fecha_fin', '>=', $desde->toDateString())
            ->orderBy('fecha_inicio')
            ->get();
    }

    /**
     * Permisos APROBADOS que tocan el periodo. Las solicitudes históricas de
     * incapacidad quedan fuera: no se reaplican a la nómina.
     *
     * @return Collection<int, SolicitudInterna>
     */
    public function permisosDe(int $colaboradorId, CarbonInterface $desde, CarbonInterface $hasta): Collection
    {
        return SolicitudInterna::query()
            // colaborador_id puede faltar en solicitudes anteriores: también se busca por su cuenta.
            ->where(fn ($q) => $q->where('colaborador_id', $colaboradorId)->orWhereIn('user_id', User::query()->where('colaborador_id', $colaboradorId)->select('id')))
            ->where('estado', EstadoSolicitudInterna::Aprobada->value)
            ->whereIn('tipo', [TipoSolicitudInterna::Permiso->value, TipoSolicitudInterna::PermisoConGoce->value, TipoSolicitudInterna::PermisoSinGoce->value])
            ->whereDate('fecha_inicio', '<=', $hasta->toDateString())
            ->whereDate('fecha_fin', '>=', $desde->toDateString())
            ->orderBy('fecha_inicio')
            ->get();
    }

    /**
     * Último día laborado: el de la baja vigente; si no hay expediente, el del colaborador.
     */
    private function ultimoDiaLaborado(Colaborador $colaborador): ?CarbonImmutable
    {
        $baja = Baja::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereNotIn('estatus', [EstatusBaja::Cancelada->value, EstatusBaja::Rechazada->value])
            ->whereNotNull('fecha_baja')
            ->orderByDesc('fecha_baja')
            ->first();

        $fecha = $baja->fecha_baja ?? $colaborador->fecha_baja;

        return $fecha !== null ? CarbonImmutable::parse($fecha->toDateString()) : null;
    }

    /**
     * @param  array<string, string|null>  $dias
     */
    private function marcar(array &$dias, CarbonInterface $inicio, CarbonInterface $fin, string $clase): int
    {
        $marcados = 0;

        for ($dia = CarbonImmutable::parse($inicio->toDateString()); $dia->lte($fin); $dia = $dia->addDay()) {
            $fecha = $dia->toDateString();

            if (array_key_exists($fecha, $dias) && $dias[$fecha] === null) {
                $dias[$fecha] = $clase;
                $marcados++;
            }
        }

        return $marcados;
    }

    private function diasEnPeriodo(CarbonInterface $inicio, CarbonInterface $fin, CarbonImmutable $desde, CarbonImmutable $hasta): int
    {
        $a = max($inicio->toDateString(), $desde->toDateString());
        $b = min($fin->toDateString(), $hasta->toDateString());

        return $a > $b ? 0 : (int) CarbonImmutable::parse($a)->diffInDays(CarbonImmutable::parse($b)) + 1;
    }

    private function gocePermiso(SolicitudInterna $permiso): bool
    {
        if ($permiso->tipo === TipoSolicitudInterna::PermisoSinGoce) {
            return false;
        }

        if ($permiso->tipo === TipoSolicitudInterna::PermisoConGoce) {
            return true;
        }

        return match ($permiso->permiso_goce) {
            GocePermiso::SinGoce->value => false,
            GocePermiso::Especial->value => (bool) config('nomina_incidencias.permiso_especial_con_goce', true),
            default => true,
        };
    }

    private function esPermisoParcial(SolicitudInterna $permiso): bool
    {
        return in_array($permiso->permiso_tipo, [TipoPermisoSolicitado::SalirTemprano->value, TipoPermisoSolicitado::LlegarTarde->value], true);
    }

    /**
     * @param  Resumen  $resumen
     */
    private function coincide(ReciboNomina $recibo, array $resumen): bool
    {
        return abs((float) ($recibo->dias_pagados ?? $this->diasBaseDe($recibo)) - $resumen['dias_pagados']) < 0.005
            && abs((float) ($recibo->dias_incapacidad ?? 0) - $resumen['dias_incapacidad']) < 0.005
            && abs((float) ($recibo->dias_falta ?? 0) - $resumen['dias_sin_goce']) < 0.005;
    }

    /**
     * @param  Resumen  $resumen
     */
    private function importeSueldo(ReciboNomina $recibo, Colaborador $colaborador, array $resumen): float
    {
        // Sueldo vigente al cierre del periodo, nunca el de hoy: un aumento
        // posterior no altera un periodo anterior. No se prorratea un cambio a
        // media quincena (regla pendiente de validar con RH).
        $vigente = $this->compensacion->vigenteEn($colaborador, $recibo->periodo_fin);
        $mensual = $vigente !== null ? $vigente['mensual'] : $this->compensacion->mensualDe($colaborador);
        $diario = $vigente !== null ? $vigente['diario'] : $this->compensacion->diarioDe($colaborador);

        // Lote: diario × días; quincenal automático: desde el mensual, para no perder centavos.
        if ($recibo->nomina_lote_id === null && $mensual !== null) {
            return $this->compensacion->proporcionalDesdeMensual($mensual, $resumen['dias_pagados']);
        }

        return round($diario * $resumen['dias_pagados'], 2);
    }

    /**
     * @param  Resumen  $resumen
     */
    private function aplicar(ReciboNomina $recibo, Colaborador $colaborador, array $resumen, User $actor): void
    {
        $conceptos = $this->recibos->conceptosEditables($recibo);
        $indice = $this->indiceConceptoSueldo($conceptos);

        if ($indice === null) {
            throw ValidationException::withMessages(['conceptos' => 'El recibo no tiene un concepto de sueldo identificable: ajústalo a mano.']);
        }

        $importe = $this->importeSueldo($recibo, $colaborador, $resumen);
        $conceptos[$indice]['importe'] = $importe;
        $conceptos[$indice]['cantidad'] = $resumen['dias_pagados'];
        $anteriores = $recibo->only(['dias_pagados', 'dias_incapacidad', 'dias_falta', 'sueldo_base', 'advertencias']);

        // Los días van antes: el PDF de un recibo emitido se genera dentro de actualizar().
        $recibo->update([
            'dias_pagados' => $resumen['dias_pagados'],
            'dias_incapacidad' => $resumen['dias_incapacidad'],
            'dias_falta' => $resumen['dias_sin_goce'],
            'sueldo_base' => $importe,
            'advertencias' => $this->avisosCombinados($recibo, $resumen['avisos']),
        ]);

        try {
            $this->recibos->actualizar($recibo, ['conceptos' => $conceptos], $actor);
        } catch (ValidationException $e) {
            if (! array_key_exists('pdf', $e->errors())) {
                $recibo->update($anteriores);
            }

            throw $e;
        }

        $this->auditoria->registrar('recibo_nomina_incidencias_aplicadas', $recibo, $actor, [
            'dias_pagados' => $resumen['dias_pagados'],
            'dias_incapacidad' => $resumen['dias_incapacidad'],
            'dias_falta' => $resumen['dias_sin_goce'],
            'regla' => $resumen['regla'],
        ]);
    }

    /**
     * @param  list<array{tipo: string, clave?: string|null, concepto: string, cantidad: float, importe: float, observaciones: string|null}>  $conceptos
     */
    private function indiceConceptoSueldo(array $conceptos): ?int
    {
        foreach ($conceptos as $i => $concepto) {
            if ($concepto['tipo'] === TipoConceptoNomina::Percepcion->value
                && (($concepto['clave'] ?? null) === '001' || str_starts_with(mb_strtolower($concepto['concepto']), 'sueldo'))) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param  Resumen  $resumen
     */
    private function registrarRectificacion(ReciboNomina $recibo, Colaborador $colaborador, array $resumen, string $motivo, ?User $actor, string $origen = 'incidencia'): bool
    {
        try {
            return DB::transaction(function () use ($recibo, $colaborador, $resumen, $motivo, $actor, $origen): bool {
                $pendiente = NominaRectificacion::query()
                    ->where('recibo_nomina_id', $recibo->id)
                    ->where('estado', EstadoRectificacionNomina::Pendiente->value)
                    ->lockForUpdate()
                    ->first();

                // Lo capturado a mano por RH no se recalcula ni se descarta solo.
                if ($pendiente?->conceptos_propuestos !== null) {
                    return false;
                }

                $importe = $this->importeSueldo($recibo, $colaborador, $resumen);
                $mismoImporte = $origen === 'incidencia' && $pendiente?->origen !== 'sueldo' || abs($importe - (float) $recibo->sueldo_base) < 0.005;

                // El cambio se deshizo (p. ej. se canceló la incapacidad): ya no hay nada que rectificar.
                if ($this->coincide($recibo, $resumen) && $mismoImporte) {
                    $pendiente?->update(['estado' => EstadoRectificacionNomina::Descartada, 'nota_resolucion' => 'Ya no aplica: '.$motivo, 'resuelta_por' => $actor?->id, 'resuelta_en' => now()]);

                    return false;
                }

                $datos = [
                    'colaborador_id' => $colaborador->id,
                    'dias_pagados_antes' => $recibo->dias_pagados,
                    'dias_pagados_despues' => $resumen['dias_pagados'],
                    'dias_incapacidad_despues' => $resumen['dias_incapacidad'],
                    'dias_falta_despues' => $resumen['dias_sin_goce'],
                    'neto_antes' => $recibo->neto,
                    'importe_sueldo_despues' => $importe,
                    'motivo' => $motivo,
                    'origen' => $pendiente !== null && $pendiente->origen === 'sueldo' ? 'sueldo' : $origen,
                ];

                if ($pendiente !== null) {
                    $pendiente->update($datos);

                    return true;
                }

                $nueva = NominaRectificacion::query()->create(['recibo_nomina_id' => $recibo->id, 'estado' => EstadoRectificacionNomina::Pendiente, ...$datos]);
                $this->auditoria->registrar('nomina_rectificacion_detectada', $recibo, $actor, ['rectificacion_id' => $nueva->id, 'motivo' => $motivo]);

                return true;
            });
        } catch (Throwable $e) {
            Log::warning('IncidenciasNominaService: no se pudo registrar la rectificación.', ['recibo_id' => $recibo->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function exigirPendiente(NominaRectificacion $rectificacion): void
    {
        if ($rectificacion->estado !== EstadoRectificacionNomina::Pendiente) {
            throw ValidationException::withMessages(['rectificacion' => 'Esta rectificación ya se resolvió.']);
        }
    }

    /**
     * @param  list<string>  $avisos
     * @return list<string>|null
     */
    private function avisosCombinados(ReciboNomina $recibo, array $avisos): ?array
    {
        $previos = array_values(array_filter($recibo->advertencias ?? [], fn (string $a): bool => ! str_starts_with($a, self::PREFIJO_AVISO)));
        $todos = [...$previos, ...$avisos];

        return $todos === [] ? null : $todos;
    }

    /**
     * @return list<string>
     */
    private function conAviso(ReciboNomina $recibo, string $aviso): array
    {
        return [...array_values(array_filter($recibo->advertencias ?? [], fn (string $a): bool => $a !== $aviso)), $aviso];
    }

    private function formatoDias(float $dias): string
    {
        return rtrim(rtrim(number_format($dias, 2, '.', ''), '0'), '.');
    }
}
