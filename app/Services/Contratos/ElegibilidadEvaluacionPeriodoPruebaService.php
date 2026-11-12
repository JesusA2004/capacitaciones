<?php

namespace App\Services\Contratos;

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoCandidato;
use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Services\Calendario\CalendarioLaboralService;
use App\Services\Reclutamiento\FaseContratadoService;
use App\Support\FechaMexico;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * ÚNICA fuente de «¿ya se le puede asignar la evaluación de periodo de prueba
 * (FO-RH-EPP-001)?». La usan el tablero «Evaluaciones de periodo de prueba»
 * (pestaña Próximas y selección en lote), «Asignar evaluación» (individual,
 * en lote y desde el ciclo laboral), el scheduler que la abre sola
 * (VencimientoContratosService) y `people:reparar-evaluaciones-periodo-prueba`.
 *
 * Se puede asignar SOLO si, en este orden:
 *  1. sigue laborando;
 *  2. no tiene ya su evaluación;
 *  3. ya alcanzó «Capacitación inicial»: aprobó su evaluación de inducción
 *     (hechos de FaseContratadoService). No se le exige a la plantilla
 *     histórica (sin candidato de reclutamiento ni registros de inducción en
 *     PEOPLE): tomó su inducción antes del sistema;
 *  4. tiene un contrato vigente con fecha de término;
 *  5. ya llegó su fecha: faltan `contratos.dias_aviso_vencimiento` días
 *     HÁBILES o menos (15, FO-RH-EPP-001) para que termine su periodo de
 *     prueba. El periodo se cuenta igual en todos lados: fecha de ingreso +
 *     duración del puesto − 1 día (y solo si eso no se puede calcular, la
 *     fecha de término de su contrato).
 *
 * @phpstan-import-type Hechos from FaseContratadoService
 *
 * @phpstan-type Elegibilidad array{puede: bool, codigo: string|null, motivo: string|null, motivo_corto: string|null, fin_periodo: string|null, asignable_desde: string|null, fase: string, fase_etiqueta: string}
 */
class ElegibilidadEvaluacionPeriodoPruebaService
{
    public const NO_LABORA = 'no_labora';

    public const YA_ASIGNADA = 'ya_asignada';

    public const INDUCCION = 'induccion';

    public const SIN_CONTRATO = 'sin_contrato';

    public const FECHA = 'fecha';

    public function __construct(
        private readonly FaseContratadoService $fases,
        private readonly CalendarioLaboralService $calendario,
    ) {}

    /**
     * @param  Hechos|null  $hechos  Hechos ya calculados en lote (FaseContratadoService::hechosParaColaboradores); null = se consultan.
     * @return Elegibilidad
     */
    public function evaluar(Colaborador $colaborador, ?ContratoLaboral $contrato, bool $yaTieneEvaluacion = false, ?CarbonInterface $hoy = null, ?array $hechos = null): array
    {
        $hoy = CarbonImmutable::parse(($hoy ?? FechaMexico::inicioDeHoy())->toDateString());
        $hechos ??= $this->fases->hechosDeColaborador($colaborador->id);
        $fin = $this->finPeriodo($colaborador, $contrato);
        $desde = $fin !== null ? $this->asignableDesde($fin) : null;
        // Para mostrar: dónde va en su recorrido sin contar esta evaluación.
        $fase = $hechos['contrato_indeterminado'] !== null ? FaseContratadoService::CONTRATO_INDETERMINADO : $hechos['fase_sin_evaluacion'];

        [$codigo, $motivo, $corto] = match (true) {
            ! $this->sigueLaborando($colaborador) => [self::NO_LABORA, 'ya no labora en la empresa.', 'Ya no labora'],
            $yaTieneEvaluacion => [self::YA_ASIGNADA, 'su evaluación ya está asignada.', 'Evaluación ya asignada'],
            ! $this->alcanzoCapacitacionInicial($hechos) => $hechos['fase_sin_evaluacion'] === FaseContratadoService::EVALUACION_INDUCCION
                ? [self::INDUCCION, 'aún no aprueba su evaluación de inducción. La evaluación de periodo de prueba se asigna cuando ya está en capacitación inicial.', 'Falta aprobar su inducción']
                : [self::INDUCCION, 'aún está en inducción: Recursos Humanos todavía no se la presenta. La evaluación de periodo de prueba se asigna cuando ya está en capacitación inicial.', 'Aún en inducción'],
            $contrato === null => [self::SIN_CONTRATO, 'aún no tiene un contrato vigente con fecha de término en el sistema. Genera su contrato de capacitación inicial (Contratos) y vuelve a intentarlo.', 'Sin contrato vigente'],
            $fin !== null && $desde !== null && $hoy->lt($desde) => [
                self::FECHA,
                sprintf('su periodo de prueba termina el %s: la evaluación se asigna a partir del %s (%d días hábiles antes).', $fin->format('d/m/Y'), $desde->format('d/m/Y'), $this->diasAnticipacion()),
                sprintf('Se asigna a partir del %s', $desde->format('d/m/Y')),
            ],
            default => [null, null, null],
        };

        return [
            'puede' => $codigo === null,
            'codigo' => $codigo,
            'motivo' => $motivo,
            'motivo_corto' => $corto,
            'fin_periodo' => $fin?->toDateString(),
            'asignable_desde' => $desde?->toDateString(),
            'fase' => $fase,
            'fase_etiqueta' => EstadoCandidato::fases()[$fase] ?? $fase,
        ];
    }

    /**
     * ¿Ya alcanzó «Capacitación inicial»? Aprobó su inducción, ya tiene
     * contrato indeterminado, o es plantilla histórica (no se le exige).
     *
     * @param  Hechos  $hechos
     */
    public function alcanzoCapacitacionInicial(array $hechos): bool
    {
        return ! $hechos['exige_induccion']
            || $hechos['contrato_indeterminado'] !== null
            || $hechos['fase_sin_evaluacion'] === FaseContratadoService::CAPACITACION_INICIAL;
    }

    /**
     * Último día del periodo de prueba: fecha de ingreso + duración del
     * puesto − 1 día (misma cuenta que ContratoLaboralService::
     * fechaFinPeriodoPrueba y que la pestaña «Próximas»). Sin fecha de
     * ingreso o sin duración en el puesto: la fecha de término del contrato.
     */
    public function finPeriodo(Colaborador $colaborador, ?ContratoLaboral $contrato): ?CarbonImmutable
    {
        $colaborador->loadMissing('puesto');
        $meses = (int) ($colaborador->puesto->meses_periodo_prueba ?? 0);

        if ($colaborador->fecha_ingreso !== null && $meses >= 1) {
            return CarbonImmutable::parse($colaborador->fecha_ingreso->toDateString())->addMonthsNoOverflow($meses)->subDay();
        }

        return $contrato?->fecha_fin !== null ? CarbonImmutable::parse($contrato->fecha_fin->toDateString()) : null;
    }

    /**
     * Primer día en que ya se puede asignar: el más antiguo desde el que
     * faltan `dias_aviso_vencimiento` días hábiles o menos para $fin (misma
     * cuenta que el scheduler: fin <= hoy + N días hábiles).
     */
    public function asignableDesde(CarbonInterface $fin): CarbonImmutable
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
     * Un colaborador en incorporación (activado_en null) SÍ sigue: su contrato
     * corre igual. Una baja (aunque no haya pasado por el cierre laboral) no.
     */
    public function sigueLaborando(Colaborador $colaborador): bool
    {
        return ! $colaborador->trashed()
            && $colaborador->estatus !== EstadoUsuario::Inactivo
            && $colaborador->estado_alta !== EstadoAltaColaborador::Baja;
    }
}
