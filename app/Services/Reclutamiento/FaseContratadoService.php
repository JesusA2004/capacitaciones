<?php

namespace App\Services\Reclutamiento;

use App\Enums\EstadoCandidato;
use App\Enums\EstadoEvaluacionInduccion;
use App\Enums\EstadoFlujoDocumento;
use App\Enums\EstadoSesionInduccion;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\EvaluacionInduccion;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\GeneratedDocument;
use App\Models\SesionInduccionColaborador;
use Illuminate\Database\Eloquent\Builder;

/**
 * Columna del tablero de un candidato YA contratado, por los hechos reales
 * registrados de su colaborador (nunca por una casilla manual ni por
 * `activado_en`):
 *
 *  1. Inducción ................... aún no le presentan la inducción.
 *  2. Evaluación de inducción ..... RH ya se la presentó (sesión «presentada»
 *                                    con él como asistente) y todavía no
 *                                    aprueba la evaluación FO-RH-EI.
 *  3. Capacitación inicial ........ aprobó la evaluación de inducción.
 *  4. Evaluación de cap. inicial .. ya tiene asignada su evaluación de
 *                                    capacitación inicial (FO-RH-EPP-001) Y
 *                                    ya había alcanzado «Capacitación inicial».
 *  5. Contrato indeterminado ...... su contrato indeterminado YA SE GENERÓ
 *                                    (y no está cancelado), o existe en papel
 *                                    y así quedó registrado (plantilla
 *                                    histórica: contrato_indeterminado_fisico_desde).
 *
 * NADIE SE SALTA FASES por un registro fuera de orden (2026-11-12): una
 * evaluación de periodo de prueba asignada a quien todavía no aprueba su
 * inducción NO lo mueve a la columna 4 — sigue donde dicen sus hechos de
 * inducción (y la evaluación sobrante se retira con
 * `people:reparar-evaluaciones-periodo-prueba`).
 *
 * La inducción solo se le exige a quien entró por el flujo de PEOPLE (tiene
 * candidato de reclutamiento o algún registro de inducción). La plantilla
 * histórica —sin candidato de reclutamiento ni registros de inducción— tomó
 * su inducción antes del sistema: para ella la evaluación de periodo de
 * prueba cuenta por sí sola.
 *
 * El contrato indeterminado (generado o en papel) es el hito final y gana
 * siempre, aunque falte un registro anterior (expedientes históricos).
 * Consultas fijas por lote: sin N+1 en el tablero.
 *
 * @phpstan-type Hechos array{fase: string, fase_sin_evaluacion: string, exige_induccion: bool, tiene_evaluacion_periodo_prueba: bool, contrato_indeterminado: string|null}
 */
class FaseContratadoService
{
    public const INDUCCION = 'induccion';

    public const EVALUACION_INDUCCION = 'evaluacion_induccion';

    public const CAPACITACION_INICIAL = 'capacitacion_inicial';

    public const EVALUACION_CAPACITACION_INICIAL = 'evaluacion_capacitacion_inicial';

    public const CONTRATO_INDETERMINADO = 'contrato_indeterminado';

    /** Contrato indeterminado generado en PEOPLE (documento no cancelado). */
    public const CONTRATO_GENERADO = 'generado';

    /** Contrato indeterminado en papel, registrado como hecho histórico. */
    public const CONTRATO_FISICO = 'fisico';

    /**
     * @param  iterable<int|null>  $idsColaborador
     * @return array<int, string> fase por colaborador_id (los ids nulos se ignoran)
     */
    public function paraColaboradores(iterable $idsColaborador): array
    {
        $fases = [];

        foreach ($this->hechosParaColaboradores($idsColaborador) as $id => $hechos) {
            $fases[$id] = $hechos['fase'];
        }

        return $fases;
    }

    /**
     * Fase y los hechos que la deciden, por colaborador. `fase_sin_evaluacion`
     * es la fase SIN contar la evaluación de periodo de prueba: con ella se
     * decide si esa evaluación se puede asignar (única fuente:
     * ElegibilidadEvaluacionPeriodoPruebaService).
     *
     * @param  iterable<int|null>  $idsColaborador
     * @return array<int, Hechos>
     */
    public function hechosParaColaboradores(iterable $idsColaborador): array
    {
        $ids = [];

        foreach ($idsColaborador as $id) {
            if ($id !== null) {
                $ids[(int) $id] = (int) $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $ids = array_values($ids);

        $conIndeterminadoGenerado = $this->conjunto(GeneratedDocument::query()
            ->whereIn('colaborador_id', $ids)
            ->where('clave_plantilla', 'contrato_indeterminado')
            ->where(fn (Builder $q) => $q->whereNull('estado_flujo')->orWhere('estado_flujo', '!=', EstadoFlujoDocumento::Cancelado->value))
            ->pluck('colaborador_id'));

        $colaboradores = Colaborador::withTrashed()
            ->whereIn('id', $ids)
            ->get(['id', 'candidato_id', 'contrato_indeterminado_fisico_desde']);
        $conIndeterminadoFisico = $this->conjunto($colaboradores
            ->filter(fn (Colaborador $c): bool => $c->contrato_indeterminado_fisico_desde !== null)
            ->pluck('id'));

        $conEvaluacionCapacitacion = $this->conjunto(EvaluacionPeriodoPrueba::query()
            ->whereIn('colaborador_id', $ids)
            ->pluck('colaborador_id'));

        $evaluacionesInduccion = EvaluacionInduccion::query()
            ->whereIn('colaborador_id', $ids)
            ->get(['colaborador_id', 'estado']);
        $induccionAprobada = $this->conjunto($evaluacionesInduccion
            ->filter(fn (EvaluacionInduccion $e): bool => $e->estado === EstadoEvaluacionInduccion::Aprobada)
            ->pluck('colaborador_id'));

        $sesiones = SesionInduccionColaborador::query()
            ->whereIn('colaborador_id', $ids)
            ->with('sesion:id,estado')
            ->get(['id', 'colaborador_id', 'sesion_induccion_id']);

        // Presentada = asistió a una sesión que RH marcó como presentada, o
        // ya tiene su evaluación de inducción abierta (solo se abre al
        // presentarla).
        $induccionPresentada = $this->conjunto($sesiones
            ->filter(fn (SesionInduccionColaborador $s): bool => $s->sesion->estado === EstadoSesionInduccion::Presentada)
            ->pluck('colaborador_id')
            ->concat($evaluacionesInduccion->pluck('colaborador_id')));

        // Entró por el flujo de PEOPLE: candidato de reclutamiento (no el
        // registro histórico) o cualquier registro de inducción.
        $porReclutamiento = $this->conjunto(Candidato::withTrashed()
            ->whereIn('colaborador_id', $ids)
            ->where('historico', false)
            ->pluck('colaborador_id')
            ->concat($colaboradores->filter(fn (Colaborador $c): bool => $c->candidato_id !== null)->pluck('id'))
            ->concat($sesiones->pluck('colaborador_id'))
            ->concat($evaluacionesInduccion->pluck('colaborador_id')));

        $hechos = [];

        foreach ($ids as $id) {
            $sinEvaluacion = match (true) {
                isset($induccionAprobada[$id]) => self::CAPACITACION_INICIAL,
                isset($induccionPresentada[$id]) => self::EVALUACION_INDUCCION,
                default => self::INDUCCION,
            };
            $exigeInduccion = isset($porReclutamiento[$id]);
            $contrato = match (true) {
                isset($conIndeterminadoGenerado[$id]) => self::CONTRATO_GENERADO,
                isset($conIndeterminadoFisico[$id]) => self::CONTRATO_FISICO,
                default => null,
            };
            // La evaluación de periodo de prueba solo cuenta si ya alcanzó
            // «Capacitación inicial» (o es plantilla histórica).
            $evaluacionCuenta = isset($conEvaluacionCapacitacion[$id])
                && (! $exigeInduccion || $sinEvaluacion === self::CAPACITACION_INICIAL);

            $hechos[$id] = [
                'fase' => match (true) {
                    $contrato !== null => self::CONTRATO_INDETERMINADO,
                    $evaluacionCuenta => self::EVALUACION_CAPACITACION_INICIAL,
                    default => $sinEvaluacion,
                },
                'fase_sin_evaluacion' => $sinEvaluacion,
                'exige_induccion' => $exigeInduccion,
                'tiene_evaluacion_periodo_prueba' => isset($conEvaluacionCapacitacion[$id]),
                'contrato_indeterminado' => $contrato,
            ];
        }

        return $hechos;
    }

    /**
     * @return Hechos
     */
    public function hechosDeColaborador(int $colaboradorId): array
    {
        return $this->hechosParaColaboradores([$colaboradorId])[$colaboradorId] ?? [
            'fase' => self::INDUCCION,
            'fase_sin_evaluacion' => self::INDUCCION,
            'exige_induccion' => false,
            'tiene_evaluacion_periodo_prueba' => false,
            'contrato_indeterminado' => null,
        ];
    }

    /**
     * Calcula en lote la columna de los contratados de una lista (tablero,
     * exportaciones, API) para que `$candidato->fase` no consulte uno por uno.
     *
     * @param  iterable<Candidato>  $candidatos
     */
    public function precargar(iterable $candidatos): void
    {
        $contratados = [];

        foreach ($candidatos as $candidato) {
            if ($candidato->estado === EstadoCandidato::Contratado
                || ($candidato->estado === EstadoCandidato::EnContratacion && $candidato->colaborador_id !== null)) {
                $contratados[] = $candidato;
            }
        }

        $hechos = $this->hechosParaColaboradores(array_map(fn (Candidato $c): ?int => $c->colaborador_id, $contratados));

        foreach ($contratados as $candidato) {
            $fase = $hechos[(int) $candidato->colaborador_id]['fase'] ?? self::INDUCCION;
            $candidato->faseContratadoPrecargada = $candidato->estado === EstadoCandidato::Contratado ? $fase : self::faseEnContratacion($fase);
        }
    }

    /**
     * Candidato todavía «en contratación» (p. ej. contratos sin firmar) cuyo
     * colaborador YA avanzó en la inducción —RH le asignó una reinducción y la
     * contestó o aprobó—: el tablero muestra ese avance real en vez de dejarlo
     * en «Contratación». Sin hechos de inducción sigue en «Contratación».
     */
    public static function faseEnContratacion(string $fase): string
    {
        return $fase === self::INDUCCION ? 'contratacion' : $fase;
    }

    public function paraColaborador(?int $colaboradorId): string
    {
        return $this->paraColaboradores([$colaboradorId])[(int) $colaboradorId] ?? self::INDUCCION;
    }

    /**
     * @param  iterable<mixed>  $valores
     * @return array<int, true>
     */
    private function conjunto(iterable $valores): array
    {
        $conjunto = [];

        foreach ($valores as $valor) {
            if (is_numeric($valor)) {
                $conjunto[(int) $valor] = true;
            }
        }

        return $conjunto;
    }
}
