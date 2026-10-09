<?php

namespace App\Services\Reclutamiento;

use App\Enums\EstadoCandidato;
use App\Models\CampanaReclutamiento;
use App\Models\Candidato;
use App\Models\Vacante;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Costo de reclutamiento encadenado campaña → vacante → contratado
 * (docs/RECLUTAMIENTO.md → "Costo por contratación").
 *
 * Fórmula de empresa (ANSI/SHRM 06001.2012):
 *   costo por contratación = (costos internos + costos externos del periodo)
 *                            / contrataciones del periodo.
 *
 * Por vacante / por persona contratada (atribución directa, sin doble
 * conteo): cada gasto se reparte en partes iguales entre SUS contratados:
 *   1) los candidatos contratados que RH registró como venidos de esa
 *      campaña (`candidatos.campana_reclutamiento_id`);
 *   2) si no hay ninguno y el gasto es de una vacante, los contratados de
 *      esa vacante sin campaña registrada.
 * Lo que no se puede atribuir así (gasto general sin vacante ni candidatos
 * ligados) se reporta como "costo general" y, por persona, como prorrateo
 * del periodo = costo general / contrataciones del periodo (la misma
 * lógica promedio del estándar). Nada se inventa: sin contrataciones el
 * costo por contratación es null.
 *
 * @phpstan-type Filtros array{empresa_id?: int|null, sucursal_id?: int|null, puesto_id?: int|null}
 */
class CostoReclutamientoService
{
    /**
     * @param  Filtros  $filtros
     * @return array{desde: string, hasta: string, gasto_externo: float, gasto_interno: float, gasto_total: float, contrataciones: int, costo_por_contratacion: float|null, gasto_general_no_atribuible: float}
     */
    public function resumen(CarbonInterface $desde, CarbonInterface $hasta, array $filtros = []): array
    {
        $campanas = $this->campanas($desde, $hasta, $filtros)->get();
        $externo = (float) $campanas->filter(fn (CampanaReclutamiento $c) => ! $c->tipo_costo->esInterno())->sum(fn (CampanaReclutamiento $c) => (float) $c->monto);
        $interno = (float) $campanas->filter(fn (CampanaReclutamiento $c) => $c->tipo_costo->esInterno())->sum(fn (CampanaReclutamiento $c) => (float) $c->monto);
        $contrataciones = $this->contratados($desde, $hasta, $filtros)->count();
        $total = $externo + $interno;

        return [
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'gasto_externo' => round($externo, 2),
            'gasto_interno' => round($interno, 2),
            'gasto_total' => round($total, 2),
            'contrataciones' => $contrataciones,
            'costo_por_contratacion' => $contrataciones > 0 ? round($total / $contrataciones, 2) : null,
            'gasto_general_no_atribuible' => round($this->gastoGeneral($campanas), 2),
        ];
    }

    /**
     * @param  Filtros  $filtros
     * @return list<array<string, mixed>>
     */
    public function porVacante(CarbonInterface $desde, CarbonInterface $hasta, array $filtros = []): array
    {
        $campanas = $this->campanas($desde, $hasta, $filtros)->whereNotNull('vacante_id')->get()->groupBy('vacante_id');
        $contratados = $this->contratados($desde, $hasta, $filtros)->whereNotNull('vacante_id')->get()->groupBy('vacante_id');
        $ids = $campanas->keys()->merge($contratados->keys())->unique()->values();

        $vacantes = Vacante::query()->with(['puesto:id,nombre', 'sucursal:id,nombre'])->whereIn('id', $ids)->get()->keyBy('id');
        $filas = [];

        foreach ($ids as $id) {
            $vacante = $vacantes->get((int) $id);

            if ($vacante === null) {
                continue;
            }

            $gastos = $campanas->get($id) ?? collect();
            $gasto = (float) $gastos->sum(fn (CampanaReclutamiento $c) => (float) $c->monto);
            $numContratados = $contratados->get($id, collect())->count();

            $filas[] = [
                'vacante_id' => $vacante->id,
                'puesto' => $vacante->puesto?->nombre,
                'sucursal' => $vacante->sucursal?->nombre,
                'estado' => $vacante->estado->value,
                'fecha_apertura' => $vacante->fecha_apertura->toDateString(),
                'fecha_cierre' => $vacante->fecha_cierre?->toDateString(),
                'dias_para_cubrir' => $vacante->fecha_cierre !== null ? (int) $vacante->fecha_apertura->diffInDays($vacante->fecha_cierre) : null,
                'gasto_externo' => round((float) $gastos->filter(fn (CampanaReclutamiento $c) => ! $c->tipo_costo->esInterno())->sum(fn (CampanaReclutamiento $c) => (float) $c->monto), 2),
                'gasto_interno' => round((float) $gastos->filter(fn (CampanaReclutamiento $c) => $c->tipo_costo->esInterno())->sum(fn (CampanaReclutamiento $c) => (float) $c->monto), 2),
                'gasto_total' => round($gasto, 2),
                'campanas' => $gastos->count(),
                'contratados' => $numContratados,
                'costo_por_contratacion' => $numContratados > 0 ? round($gasto / $numContratados, 2) : null,
            ];
        }

        usort($filas, fn (array $a, array $b) => $b['gasto_total'] <=> $a['gasto_total']);

        return $filas;
    }

    /**
     * Cuánto costó cada persona contratada en el periodo.
     *
     * @param  Filtros  $filtros
     * @return list<array<string, mixed>>
     */
    public function porContratado(CarbonInterface $desde, CarbonInterface $hasta, array $filtros = []): array
    {
        $contratados = $this->contratados($desde, $hasta, $filtros)
            ->with(['vacante:id,puesto_id,sucursal_id', 'puestoObjetivo:id,nombre', 'sucursal:id,nombre', 'campana:id,nombre,canal,tipo_costo,monto,vacante_id'])
            ->get();

        $directo = $this->costoDirectoPorCandidato($contratados);
        $general = $this->gastoGeneral($this->campanas($desde, $hasta, $filtros)->get());
        $prorrateo = $contratados->count() > 0 ? $general / $contratados->count() : 0.0;

        return array_values($contratados->map(fn (Candidato $c) => [
            'candidato_id' => $c->id,
            'colaborador_id' => $c->colaborador_id,
            'nombre' => $c->nombreCompleto(),
            'puesto' => $c->puestoObjetivo?->nombre,
            'sucursal' => $c->sucursal?->nombre,
            'vacante_id' => $c->vacante_id,
            'campana' => $c->campana->nombre ?? $c->campana?->canal->etiqueta(),
            'fuente' => $c->campana?->canal->etiqueta() ?? $c->fuente,
            'contratado_en' => $c->contratado_en?->toDateString(),
            'costo_directo' => round($directo[$c->id] ?? 0.0, 2),
            'prorrateo_general' => round($prorrateo, 2),
            'costo_total' => round(($directo[$c->id] ?? 0.0) + $prorrateo, 2),
        ])->sortByDesc('costo_total')->values()->all());
    }

    /**
     * @param  Collection<int, Candidato>  $contratados
     * @return array<int, float> candidato_id → costo directo atribuido
     */
    private function costoDirectoPorCandidato(Collection $contratados): array
    {
        $idsCampanas = $contratados->pluck('campana_reclutamiento_id')->filter()->unique();
        $idsVacantes = $contratados->pluck('vacante_id')->filter()->unique();

        $campanas = CampanaReclutamiento::query()
            ->whereIn('id', $idsCampanas)
            ->orWhereIn('vacante_id', $idsVacantes)
            ->get();

        $costo = [];

        foreach ($campanas as $campana) {
            $atribuidos = $this->contratadosDeCampana($campana);

            if ($atribuidos->isEmpty()) {
                continue;
            }

            $parte = (float) $campana->monto / $atribuidos->count();

            foreach ($atribuidos as $id) {
                $costo[(int) $id] = ($costo[(int) $id] ?? 0.0) + $parte;
            }
        }

        return $costo;
    }

    /**
     * Costo por colaborador de cada campaña: su monto entre las personas
     * contratadas que produjo (misma atribución que el costo por persona).
     * Sin contratados todavía, el costo por colaborador es null (no se
     * inventa).
     *
     * @param  iterable<CampanaReclutamiento>  $campanas
     * @return array{gasto: float, contratados: int, costo_por_colaborador: float|null, por_campana: array<int, array{candidatos: int, contratados: int, costo_por_colaborador: float|null}>}
     */
    public function porCampana(iterable $campanas): array
    {
        $porCampana = [];
        $todos = [];
        $gasto = 0.0;

        foreach ($campanas as $campana) {
            $contratados = $this->contratadosDeCampana($campana);
            $gasto += (float) $campana->monto;

            foreach ($contratados as $id) {
                $todos[$id] = true;
            }

            $candidatos = (int) ($campana->candidatos_generados ?? Candidato::query()->where('campana_reclutamiento_id', $campana->id)->count());
            // Embudo real de la campaña: candidatos registrados como venidos de ella.
            $deCampana = fn () => Candidato::query()->where('campana_reclutamiento_id', $campana->id);
            $fechasContratacion = Candidato::query()->whereIn('id', $contratados)->whereNotNull('contratado_en')->pluck('contratado_en');
            $inicioCampana = $campana->fecha_inicio ?? $campana->created_at;
            $diasCobertura = $inicioCampana !== null && $fechasContratacion->isNotEmpty()
                ? (int) round($fechasContratacion->avg(fn ($fecha) => max(0, (int) $inicioCampana->copy()->startOfDay()->diffInDays(Carbon::parse($fecha)->startOfDay()))))
                : null;

            $porCampana[$campana->id] = [
                'candidatos' => $candidatos,
                'entrevistas' => $deCampana()->whereHas('entrevistas')->count(),
                'psicometricos' => $deCampana()->whereHas('psicometricas')->count(),
                'contratados' => $contratados->count(),
                'costo_por_candidato' => $candidatos > 0 ? round((float) $campana->monto / $candidatos, 2) : null,
                'costo_por_colaborador' => $contratados->isNotEmpty() ? round((float) $campana->monto / $contratados->count(), 2) : null,
                'conversion' => $candidatos > 0 ? round($contratados->count() / $candidatos * 100, 1) : null,
                'dias_cobertura' => $diasCobertura,
            ];
        }

        return [
            'gasto' => round($gasto, 2),
            'contratados' => count($todos),
            'costo_por_colaborador' => $todos !== [] ? round($gasto / count($todos), 2) : null,
            'por_campana' => $porCampana,
        ];
    }

    /**
     * Personas contratadas que produjo una campaña (de cualquier periodo,
     * para que su costo se reparta entre todas): las que RH registró como
     * venidas de ella o, si no hay ninguna y el gasto es de una vacante,
     * los contratados de esa vacante sin campaña registrada.
     *
     * @return Collection<int, int>
     */
    private function contratadosDeCampana(CampanaReclutamiento $campana): Collection
    {
        $atribuidos = Candidato::query()
            ->where('estado', EstadoCandidato::Contratado->value)
            ->where('campana_reclutamiento_id', $campana->id)
            ->pluck('id');

        if ($atribuidos->isEmpty() && $campana->vacante_id !== null) {
            $atribuidos = Candidato::query()
                ->where('estado', EstadoCandidato::Contratado->value)
                ->where('vacante_id', $campana->vacante_id)
                ->whereNull('campana_reclutamiento_id')
                ->pluck('id');
        }

        return $atribuidos->map(fn (mixed $id): int => (int) $id)->values();
    }

    /**
     * Gasto del periodo que no se puede atribuir a nadie: sin vacante y sin
     * candidatos registrados de esa campaña.
     *
     * @param  Collection<int, CampanaReclutamiento>|\Illuminate\Database\Eloquent\Collection<int, CampanaReclutamiento>  $campanas
     */
    private function gastoGeneral(Collection $campanas): float
    {
        $conCandidatos = Candidato::query()->whereIn('campana_reclutamiento_id', $campanas->pluck('id'))->pluck('campana_reclutamiento_id')->unique()->all();

        return (float) $campanas
            ->filter(fn (CampanaReclutamiento $c) => $c->vacante_id === null && ! in_array($c->id, $conCandidatos, true))
            ->sum(fn (CampanaReclutamiento $c) => (float) $c->monto);
    }

    /**
     * @param  Filtros  $filtros
     * @return Builder<CampanaReclutamiento>
     */
    private function campanas(CarbonInterface $desde, CarbonInterface $hasta, array $filtros): Builder
    {
        $inicio = $desde->year * 100 + $desde->month;
        $fin = $hasta->year * 100 + $hasta->month;

        return CampanaReclutamiento::query()
            ->whereRaw('(anio * 100 + mes) between ? and ?', [$inicio, $fin])
            ->when($filtros['empresa_id'] ?? null, fn (Builder $q, int $v) => $q->where('empresa_id', $v))
            ->when($filtros['sucursal_id'] ?? null, fn (Builder $q, int $v) => $q->where('sucursal_id', $v))
            ->when($filtros['puesto_id'] ?? null, fn (Builder $q, int $v) => $q->where('puesto_id', $v));
    }

    /**
     * Contratados del periodo por su fecha real de contratación
     * (`candidatos.contratado_en`).
     *
     * @param  Filtros  $filtros
     * @return Builder<Candidato>
     */
    private function contratados(CarbonInterface $desde, CarbonInterface $hasta, array $filtros): Builder
    {
        return Candidato::query()
            ->where('estado', EstadoCandidato::Contratado->value)
            ->whereNotNull('contratado_en')
            ->whereBetween('contratado_en', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])
            ->when($filtros['empresa_id'] ?? null, fn (Builder $q, int $v) => $q->where('empresa_id', $v))
            ->when($filtros['sucursal_id'] ?? null, fn (Builder $q, int $v) => $q->where('sucursal_id', $v))
            ->when($filtros['puesto_id'] ?? null, fn (Builder $q, int $v) => $q->where('puesto_objetivo_id', $v));
    }
}
