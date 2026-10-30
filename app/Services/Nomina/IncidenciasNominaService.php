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
     * RH aplica la rectificación: recalcula con los datos de HOY (no con los de
     * cuando se detectó), genera un PDF nuevo y el anterior queda en el
     * historial del expediente.
     */
    public function aplicarRectificacion(NominaRectificacion $rectificacion, User $actor): NominaRectificacion
    {
        $this->exigirPendiente($rectificacion);
        $recibo = $rectificacion->recibo;
        $colaborador = $recibo->colaborador;
        $resumen = $this->resumen($colaborador, $recibo->periodo_inicio, $recibo->periodo_fin, $this->diasBaseDe($recibo));

        $resolver = function () use ($rectificacion, $actor): void {
            $rectificacion->update(['estado' => EstadoRectificacionNomina::Aplicada, 'resuelta_por' => $actor->id, 'resuelta_en' => now()]);
        };

        try {
            $this->aplicar($recibo, $colaborador, $resumen, $actor);
        } catch (ValidationException $e) {
            // Los montos ya se guardaron y solo falló el PDF: se repara desde el recibo.
            if (array_key_exists('pdf', $e->errors())) {
                $resolver();
            }

            throw $e;
        }

        $resolver();
        $this->auditoria->registrar('nomina_rectificacion_aplicada', $recibo, $actor, ['rectificacion_id' => $rectificacion->id, 'dias_pagados' => $resumen['dias_pagados']]);

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
        $mensual = $this->compensacion->mensualDe($colaborador);

        // Lote: diario × días; quincenal automático: desde el mensual, para no perder centavos.
        if ($recibo->nomina_lote_id === null && $mensual !== null) {
            return $this->compensacion->proporcionalDesdeMensual($mensual, $resumen['dias_pagados']);
        }

        return round($this->compensacion->diarioDe($colaborador) * $resumen['dias_pagados'], 2);
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
    private function registrarRectificacion(ReciboNomina $recibo, Colaborador $colaborador, array $resumen, string $motivo, ?User $actor): void
    {
        try {
            DB::transaction(function () use ($recibo, $colaborador, $resumen, $motivo, $actor): void {
                $pendiente = NominaRectificacion::query()
                    ->where('recibo_nomina_id', $recibo->id)
                    ->where('estado', EstadoRectificacionNomina::Pendiente->value)
                    ->lockForUpdate()
                    ->first();

                // El cambio se deshizo (p. ej. se canceló la incapacidad): ya no hay nada que rectificar.
                if ($this->coincide($recibo, $resumen)) {
                    $pendiente?->update(['estado' => EstadoRectificacionNomina::Descartada, 'nota_resolucion' => 'Ya no aplica: '.$motivo, 'resuelta_por' => $actor?->id, 'resuelta_en' => now()]);

                    return;
                }

                $datos = [
                    'colaborador_id' => $colaborador->id,
                    'dias_pagados_antes' => $recibo->dias_pagados,
                    'dias_pagados_despues' => $resumen['dias_pagados'],
                    'dias_incapacidad_despues' => $resumen['dias_incapacidad'],
                    'dias_falta_despues' => $resumen['dias_sin_goce'],
                    'neto_antes' => $recibo->neto,
                    'importe_sueldo_despues' => $this->importeSueldo($recibo, $colaborador, $resumen),
                    'motivo' => $motivo,
                ];

                if ($pendiente !== null) {
                    $pendiente->update($datos);

                    return;
                }

                $nueva = NominaRectificacion::query()->create(['recibo_nomina_id' => $recibo->id, 'estado' => EstadoRectificacionNomina::Pendiente, ...$datos]);
                $this->auditoria->registrar('nomina_rectificacion_detectada', $recibo, $actor, ['rectificacion_id' => $nueva->id, 'motivo' => $motivo]);
            });
        } catch (Throwable $e) {
            Log::warning('IncidenciasNominaService: no se pudo registrar la rectificación.', ['recibo_id' => $recibo->id, 'error' => $e->getMessage()]);
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
