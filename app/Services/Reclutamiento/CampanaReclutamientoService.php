<?php

namespace App\Services\Reclutamiento;

use App\Enums\CanalReclutamiento;
use App\Enums\EstadoCandidato;
use App\Models\CampanaReclutamiento;
use App\Models\Candidato;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;

/**
 * KPIs de gasto de reclutamiento (docs de referencia: encargo de campañas
 * de reclutamiento). No decide nada, solo agrega: App\Models\CampanaReclutamiento
 * (lo que RH capturó que gastó) y App\Models\Candidato (lo que realmente
 * entró al pipeline), cruzados por canal/periodo.
 *
 * Nota sobre atribución: `Candidato::fuente` es texto libre capturado a mano
 * por RH al dar de alta un candidato (no está restringido a los mismos
 * value-strings que App\Enums\CanalReclutamiento) — el cruce candidatos↔canal
 * solo es fiable cuando RH capturó la fuente igual al value del canal
 * ('meta', 'indeed', 'computrabajo', 'linkedin', 'referidos', 'otros'). Por
 * eso el fallback por fuente es exactamente eso, un fallback: se usa
 * candidatos_generados de la campaña si RH ya lo capturó a mano, y solo se
 * cae a contar candidatos por fuente cuando esa columna quedó vacía.
 */
class CampanaReclutamientoService
{
    /**
     * Resumen agregado del periodo (opcionalmente acotado por filtros de
     * empresa/sucursal/departamento/puesto/canal, los mismos que el listado
     * de campañas).
     *
     * @param  array<string, int|string|null>  $filtros
     * @return array{
     *     gasto_total: float,
     *     candidatos_generados: int,
     *     costo_por_candidato: float,
     *     contratados: int,
     *     costo_por_contratacion: float,
     * }
     */
    public function resumenPeriodo(int $mes, int $anio, array $filtros = []): array
    {
        $campanas = $this->queryFiltrada($mes, $anio, $filtros)->get();

        $gastoTotal = (float) $campanas->sum(fn (CampanaReclutamiento $c) => (float) $c->monto);
        $candidatosGenerados = $this->candidatosGeneradosPorCanal($campanas, $mes, $anio)->sum();
        $contratados = $this->contratadosPorCanal($campanas, $mes, $anio)->sum();

        return [
            'gasto_total' => $gastoTotal,
            'candidatos_generados' => $candidatosGenerados,
            'costo_por_candidato' => $candidatosGenerados > 0 ? $gastoTotal / $candidatosGenerados : 0.0,
            'contratados' => $contratados,
            'costo_por_contratacion' => $contratados > 0 ? $gastoTotal / $contratados : 0.0,
        ];
    }

    /**
     * Gasto agrupado por puesto (solo campañas CON puesto_id) + un bloque
     * aparte de "costo general" para las campañas sin puesto asignado.
     *
     * El gasto general NUNCA se reparte entre puestos aquí: no existe una
     * base objetiva para saber a qué puesto concreto atribuir, por ejemplo,
     * una campaña de Meta Ads sin puesto_id — inventar un reparto
     * (p. ej. por número de vacantes abiertas de cada puesto) sería un dato
     * fabricado, no uno medido. Si en el futuro se decide repartir, debe
     * documentarse aquí la fórmula exacta usada.
     *
     * @return array{
     *     por_puesto: array<int, array{puesto_id: int, puesto: string, gasto: float, candidatos_generados: int, costo_por_candidato: float}>,
     *     costo_general: array{gasto: float, candidatos_generados: int, costo_por_candidato: float},
     * }
     */
    public function resumenPorPuesto(int $mes, int $anio): array
    {
        $campanas = CampanaReclutamiento::query()
            ->where('mes', $mes)
            ->where('anio', $anio)
            ->with('puesto:id,nombre')
            ->get();

        $conPuesto = $campanas->whereNotNull('puesto_id');
        $sinPuesto = $campanas->whereNull('puesto_id');

        $porPuesto = $conPuesto
            ->groupBy('puesto_id')
            ->map(function (Collection|SupportCollection $grupo) use ($mes, $anio) {
                /** @var CampanaReclutamiento $primera */
                $primera = $grupo->first();
                $gasto = (float) $grupo->sum(fn (CampanaReclutamiento $c) => (float) $c->monto);
                $candidatos = $this->candidatosGeneradosPorCanal($grupo, $mes, $anio)->sum();

                return [
                    'puesto_id' => $primera->puesto_id,
                    'puesto' => $primera->puesto->nombre ?? 'Puesto sin nombre',
                    'gasto' => $gasto,
                    'candidatos_generados' => $candidatos,
                    'costo_por_candidato' => $candidatos > 0 ? $gasto / $candidatos : 0.0,
                ];
            })
            ->values()
            ->all();

        $gastoGeneral = (float) $sinPuesto->sum(fn (CampanaReclutamiento $c) => (float) $c->monto);
        $candidatosGeneral = $this->candidatosGeneradosPorCanal($sinPuesto, $mes, $anio)->sum();

        return [
            'por_puesto' => $porPuesto,
            'costo_general' => [
                'gasto' => $gastoGeneral,
                'candidatos_generados' => $candidatosGeneral,
                'costo_por_candidato' => $candidatosGeneral > 0 ? $gastoGeneral / $candidatosGeneral : 0.0,
            ],
        ];
    }

    /**
     * @param  array<string, int|string|null>  $filtros
     * @return Builder<CampanaReclutamiento>
     */
    private function queryFiltrada(int $mes, int $anio, array $filtros): Builder
    {
        return CampanaReclutamiento::query()
            ->where('mes', $mes)
            ->where('anio', $anio)
            ->when($filtros['empresa_id'] ?? null, fn ($q, $v) => $q->where('empresa_id', $v))
            ->when($filtros['sucursal_id'] ?? null, fn ($q, $v) => $q->where('sucursal_id', $v))
            ->when($filtros['departamento_id'] ?? null, fn ($q, $v) => $q->where('departamento_id', $v))
            ->when($filtros['puesto_id'] ?? null, fn ($q, $v) => $q->where('puesto_id', $v))
            ->when($filtros['canal'] ?? null, fn ($q, $v) => $q->where('canal', $v));
    }

    /**
     * Candidatos generados por canal dentro del periodo, sin doble conteo:
     * si al menos una campaña de ese canal en el periodo quedó sin capturar
     * `candidatos_generados`, se cuenta UNA sola vez (no por fila) cuántos
     * candidatos con `fuente` = canal se registraron en el mes — sumarlo por
     * cada fila de campaña duplicaría el conteo cuando el canal tiene varias
     * campañas en el mismo periodo.
     *
     * @param  Collection<int, CampanaReclutamiento>|SupportCollection<int, CampanaReclutamiento>  $campanas
     * @return SupportCollection<string, int> candidatos generados, indexado por value de canal
     */
    private function candidatosGeneradosPorCanal(Collection|SupportCollection $campanas, int $mes, int $anio): SupportCollection
    {
        [$inicio, $fin] = $this->rangoPeriodo($mes, $anio);

        return $campanas
            ->groupBy(fn (CampanaReclutamiento $c) => $c->canal->value)
            ->map(function (Collection|SupportCollection $grupo, string $canal) use ($inicio, $fin) {
                $conColumna = $grupo->whereNotNull('candidatos_generados');
                $total = (int) $conColumna->sum('candidatos_generados');

                if ($grupo->count() > $conColumna->count()) {
                    $total += Candidato::query()
                        ->where('fuente', $canal)
                        ->whereBetween('created_at', [$inicio, $fin])
                        ->count();
                }

                return $total;
            });
    }

    /**
     * Contratados atribuibles a cada canal dentro del periodo. No existe un
     * campo de "fecha de conversión a contratado" propio: se usa
     * `updated_at` del candidato como proxy razonable de cuándo pasó a
     * EstadoCandidato::Contratado (última vez que se guardó el registro
     * estando ya en ese estado terminal — ver App\Enums\EstadoCandidato).
     *
     * @param  Collection<int, CampanaReclutamiento>|SupportCollection<int, CampanaReclutamiento>  $campanas
     * @return SupportCollection<string, int<0, max>>
     */
    private function contratadosPorCanal(Collection|SupportCollection $campanas, int $mes, int $anio): SupportCollection
    {
        [$inicio, $fin] = $this->rangoPeriodo($mes, $anio);

        return $campanas
            ->pluck('canal')
            ->unique()
            ->mapWithKeys(fn (CanalReclutamiento $canal) => [
                $canal->value => Candidato::query()
                    ->where('fuente', $canal->value)
                    ->where('estado', EstadoCandidato::Contratado->value)
                    ->whereBetween('updated_at', [$inicio, $fin])
                    ->count(),
            ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function rangoPeriodo(int $mes, int $anio): array
    {
        $inicio = Carbon::now()->setDate($anio, $mes, 1)->startOfMonth();

        return [$inicio, $inicio->clone()->endOfMonth()];
    }
}
