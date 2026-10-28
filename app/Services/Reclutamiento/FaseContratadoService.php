<?php

namespace App\Services\Reclutamiento;

use App\Enums\EstadoCandidato;
use App\Enums\EstadoEvaluacionInduccion;
use App\Enums\EstadoFlujoDocumento;
use App\Enums\EstadoSesionInduccion;
use App\Models\Candidato;
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
 *                                    capacitación inicial (FO-RH-EPP-001).
 *  5. Contrato indeterminado ...... su contrato indeterminado YA SE GENERÓ
 *                                    (y no está cancelado).
 *
 * Se toma el hito MÁS AVANZADO que exista: si alguien tiene contrato
 * indeterminado generado está ahí aunque falte un registro anterior
 * (expedientes históricos). Consultas fijas por lote: sin N+1 en el tablero.
 */
class FaseContratadoService
{
    public const INDUCCION = 'induccion';

    public const EVALUACION_INDUCCION = 'evaluacion_induccion';

    public const CAPACITACION_INICIAL = 'capacitacion_inicial';

    public const EVALUACION_CAPACITACION_INICIAL = 'evaluacion_capacitacion_inicial';

    public const CONTRATO_INDETERMINADO = 'contrato_indeterminado';

    /**
     * @param  iterable<int|null>  $idsColaborador
     * @return array<int, string> fase por colaborador_id (los ids nulos se ignoran)
     */
    public function paraColaboradores(iterable $idsColaborador): array
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

        $conIndeterminado = $this->conjunto(GeneratedDocument::query()
            ->whereIn('colaborador_id', $ids)
            ->where('clave_plantilla', 'contrato_indeterminado')
            ->where(fn (Builder $q) => $q->whereNull('estado_flujo')->orWhere('estado_flujo', '!=', EstadoFlujoDocumento::Cancelado->value))
            ->pluck('colaborador_id'));

        $conEvaluacionCapacitacion = $this->conjunto(EvaluacionPeriodoPrueba::query()
            ->whereIn('colaborador_id', $ids)
            ->pluck('colaborador_id'));

        $evaluacionesInduccion = EvaluacionInduccion::query()
            ->whereIn('colaborador_id', $ids)
            ->get(['colaborador_id', 'estado']);
        $induccionAprobada = $this->conjunto($evaluacionesInduccion
            ->filter(fn (EvaluacionInduccion $e): bool => $e->estado === EstadoEvaluacionInduccion::Aprobada)
            ->pluck('colaborador_id'));

        // Presentada = asistió a una sesión que RH marcó como presentada, o
        // ya tiene su evaluación de inducción abierta (solo se abre al
        // presentarla).
        $induccionPresentada = $this->conjunto(SesionInduccionColaborador::query()
            ->whereIn('colaborador_id', $ids)
            ->whereHas('sesion', fn (Builder $q) => $q->where('estado', EstadoSesionInduccion::Presentada->value))
            ->pluck('colaborador_id')
            ->concat($evaluacionesInduccion->pluck('colaborador_id')));

        $fases = [];

        foreach ($ids as $id) {
            $fases[$id] = match (true) {
                isset($conIndeterminado[$id]) => self::CONTRATO_INDETERMINADO,
                isset($conEvaluacionCapacitacion[$id]) => self::EVALUACION_CAPACITACION_INICIAL,
                isset($induccionAprobada[$id]) => self::CAPACITACION_INICIAL,
                isset($induccionPresentada[$id]) => self::EVALUACION_INDUCCION,
                default => self::INDUCCION,
            };
        }

        return $fases;
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
            if ($candidato->estado === EstadoCandidato::Contratado) {
                $contratados[] = $candidato;
            }
        }

        $fases = $this->paraColaboradores(array_map(fn (Candidato $c): ?int => $c->colaborador_id, $contratados));

        foreach ($contratados as $candidato) {
            $candidato->faseContratadoPrecargada = $fases[(int) $candidato->colaborador_id] ?? self::INDUCCION;
        }
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
