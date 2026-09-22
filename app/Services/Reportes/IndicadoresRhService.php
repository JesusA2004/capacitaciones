<?php

namespace App\Services\Reportes;

use App\Enums\EstadoCandidato;
use App\Enums\EstadoContratoLaboral;
use App\Enums\EstadoUsuario;
use App\Enums\EstadoVacante;
use App\Enums\TipoMovimientoLaboral;
use App\Models\CampanaReclutamiento;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\MovimientoLaboral;
use App\Models\User;
use App\Models\Vacante;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Headcount\HeadcountService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Indicadores de RH calculados 100% en backend con agregados SQL (count/sum
 * y columnas puntuales), acotados por el alcance organizacional del
 * usuario y por sucursal/empresa opcionales. Web y app solo consumen el
 * resultado: nunca descargan registros completos para calcular.
 *
 * Definiciones (también en docs/backend-rh-completion.md):
 *  - plantilla activa: colaboradores vigentes (activos + en incorporación).
 *  - plantilla autorizada / cobertura / vacantes / excedentes: HeadcountService::coberturaDetallada().
 *  - rotación %: bajas del periodo / plantilla promedio del periodo × 100,
 *    plantilla promedio = (plantilla al inicio + plantilla al final) / 2,
 *    plantilla al inicio = activa actual − altas del periodo + bajas del periodo.
 *  - permanencia promedio: días entre fecha de ingreso y fecha de baja (bajas
 *    del periodo) o hoy (activos).
 *  - tiempo de contratación: días de apertura→cierre de vacantes cubiertas en
 *    el periodo, y días de registro→contratación de candidatos contratados.
 *  - costo por contratación: inversión en campañas del periodo / candidatos contratados en el periodo.
 */
class IndicadoresRhService
{
    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly HeadcountService $headcount,
    ) {}

    /**
     * @param  array{desde?: string|null, hasta?: string|null, sucursal_id?: int|string|null, empresa_id?: int|string|null, dias_vencimiento?: int|string|null}  $filtros
     * @return array<string, mixed>
     */
    public function calcular(User $usuario, array $filtros = []): array
    {
        $desde = Carbon::parse($filtros['desde'] ?? now()->startOfMonth())->startOfDay();
        $hasta = Carbon::parse($filtros['hasta'] ?? now())->endOfDay();
        $sucursales = $this->sucursalesEnAlcance($usuario, $filtros);
        $empresaId = isset($filtros['empresa_id']) ? (int) $filtros['empresa_id'] : null;

        $cobertura = $this->headcount->coberturaDetallada($sucursales, $empresaId);
        $colaboradores = $this->colaboradores($sucursales, $empresaId);

        $activa = (clone $colaboradores)->whereIn('estatus', EstadoUsuario::valoresVigentes())->count();
        $bajas = $this->movimientos($sucursales, $empresaId, TipoMovimientoLaboral::Baja, $desde, $hasta)->count();
        $altas = $this->movimientos($sucursales, $empresaId, TipoMovimientoLaboral::Alta, $desde, $hasta)->count();
        $inicio = max($activa - $altas + $bajas, 0);
        $promedio = ($inicio + $activa) / 2;

        $contratados = $this->candidatos($sucursales, $empresaId)
            ->where('estado', EstadoCandidato::Contratado->value)
            ->whereBetween('contratado_en', [$desde, $hasta]);

        $inversion = $this->inversion($sucursales, $empresaId, $desde, $hasta);
        $numContratados = (clone $contratados)->count();
        $diasVencimiento = max(1, (int) ($filtros['dias_vencimiento'] ?? 30));

        return [
            'periodo' => ['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString()],
            'plantilla_activa' => $activa,
            'plantilla_autorizada' => $cobertura['totales']['autorizados'],
            'cobertura' => $cobertura['totales']['cobertura'],
            'vacantes_plantilla' => $cobertura['totales']['vacantes'],
            'excedentes_plantilla' => $cobertura['totales']['excedentes'],
            'vacantes_abiertas' => $this->vacantes($sucursales, $empresaId)
                ->whereIn('estado', [EstadoVacante::Abierta->value, EstadoVacante::EnReclutamiento->value, EstadoVacante::ConCandidatos->value, EstadoVacante::EnRevision->value])
                ->count(),
            'altas_periodo' => $altas,
            'bajas_periodo' => $bajas,
            'rotacion' => $promedio > 0 ? round($bajas / $promedio * 100, 2) : 0.0,
            'permanencia_promedio_dias' => [
                'activos' => $this->promedioDias((clone $colaboradores)->whereIn('estatus', EstadoUsuario::valoresVigentes())->whereNotNull('fecha_ingreso')->pluck('fecha_ingreso'), null),
                'bajas_periodo' => $this->permanenciaBajas($sucursales, $empresaId, $desde, $hasta),
            ],
            'contratos_por_vencer' => [
                'dias' => $diasVencimiento,
                'total' => $this->contratosPorVencer($sucursales, $empresaId, $diasVencimiento),
            ],
            'tiempo_contratacion_dias' => [
                'vacantes' => $this->tiempoVacantes($sucursales, $empresaId, $desde, $hasta),
                'candidatos' => $this->tiempoCandidatos((clone $contratados)->get(['created_at', 'contratado_en'])),
            ],
            'contratados_periodo' => $numContratados,
            'inversion_reclutamiento' => $inversion,
            'costo_por_contratacion' => $numContratados > 0 ? round($inversion / $numContratados, 2) : null,
            'embudo_candidatos' => $this->embudo($sucursales, $empresaId, $desde, $hasta),
        ];
    }

    /**
     * @param  array{sucursal_id?: int|string|null}  $filtros
     * @return Collection<int, int>|null null = sin restricción (alcance global y sin filtro).
     */
    private function sucursalesEnAlcance(User $usuario, array $filtros): ?Collection
    {
        $visibles = $this->alcance->tieneAlcanceGlobal($usuario) ? null : $this->alcance->sucursalesVisiblesIds($usuario)->map(fn ($id) => (int) $id);

        if (! empty($filtros['sucursal_id'])) {
            $sucursal = (int) $filtros['sucursal_id'];

            return collect($visibles === null || $visibles->contains($sucursal) ? [$sucursal] : []);
        }

        return $visibles;
    }

    /**
     * @param  Collection<int, int>|null  $sucursales
     * @return Builder<Colaborador>
     */
    private function colaboradores(?Collection $sucursales, ?int $empresaId): Builder
    {
        return Colaborador::query()
            ->when($sucursales !== null, fn (Builder $q) => $q->whereIn('sucursal_principal_id', $sucursales))
            ->when($empresaId !== null, fn (Builder $q) => $q->whereHas('sucursalPrincipal', fn (Builder $s) => $s->where('empresa_id', $empresaId)));
    }

    /**
     * @param  Collection<int, int>|null  $sucursales
     * @return Builder<MovimientoLaboral>
     */
    private function movimientos(?Collection $sucursales, ?int $empresaId, TipoMovimientoLaboral $tipo, CarbonInterface $desde, CarbonInterface $hasta): Builder
    {
        $columnaSucursal = $tipo === TipoMovimientoLaboral::Baja ? 'sucursal_anterior_id' : 'sucursal_nueva_id';
        $columnaEmpresa = $tipo === TipoMovimientoLaboral::Baja ? 'empresa_anterior_id' : 'empresa_nueva_id';

        return MovimientoLaboral::query()
            ->where('tipo_movimiento', $tipo->value)
            ->whereBetween('fecha_movimiento', [$desde->toDateString(), $hasta->toDateString()])
            ->when($sucursales !== null, fn (Builder $q) => $q->whereIn($columnaSucursal, $sucursales))
            ->when($empresaId !== null, fn (Builder $q) => $q->where($columnaEmpresa, $empresaId));
    }

    /**
     * @param  Collection<int, int>|null  $sucursales
     * @return Builder<Candidato>
     */
    private function candidatos(?Collection $sucursales, ?int $empresaId): Builder
    {
        return Candidato::query()
            ->when($sucursales !== null, fn (Builder $q) => $q->whereIn('sucursal_id', $sucursales))
            ->when($empresaId !== null, fn (Builder $q) => $q->where('empresa_id', $empresaId));
    }

    /**
     * @param  Collection<int, int>|null  $sucursales
     * @return Builder<Vacante>
     */
    private function vacantes(?Collection $sucursales, ?int $empresaId): Builder
    {
        return Vacante::query()
            ->when($sucursales !== null, fn (Builder $q) => $q->whereIn('sucursal_id', $sucursales))
            ->when($empresaId !== null, fn (Builder $q) => $q->where('empresa_id', $empresaId));
    }

    /**
     * @param  Collection<int, int>|null  $sucursales
     */
    private function inversion(?Collection $sucursales, ?int $empresaId, CarbonInterface $desde, CarbonInterface $hasta): float
    {
        $inicio = ((int) $desde->format('Y')) * 100 + (int) $desde->format('n');
        $fin = ((int) $hasta->format('Y')) * 100 + (int) $hasta->format('n');

        return round((float) CampanaReclutamiento::query()
            ->whereRaw('(anio * 100 + mes) between ? and ?', [$inicio, $fin])
            ->when($sucursales !== null, fn (Builder $q) => $q->where(fn (Builder $s) => $s->whereIn('sucursal_id', $sucursales)->orWhereNull('sucursal_id')))
            ->when($empresaId !== null, fn (Builder $q) => $q->where(fn (Builder $s) => $s->where('empresa_id', $empresaId)->orWhereNull('empresa_id')))
            ->sum('monto'), 2);
    }

    /**
     * @param  Collection<int, int>|null  $sucursales
     */
    private function contratosPorVencer(?Collection $sucursales, ?int $empresaId, int $dias): int
    {
        return ContratoLaboral::query()
            ->where('estado', EstadoContratoLaboral::Vigente->value)
            ->whereNotNull('fecha_fin')
            ->whereDate('fecha_fin', '<=', now()->addDays($dias)->toDateString())
            ->whereIn('colaborador_id', $this->colaboradores($sucursales, $empresaId)->select('id'))
            ->count();
    }

    /**
     * @param  Collection<int, int>|null  $sucursales
     */
    private function permanenciaBajas(?Collection $sucursales, ?int $empresaId, CarbonInterface $desde, CarbonInterface $hasta): ?float
    {
        $filas = $this->colaboradores($sucursales, $empresaId)
            ->withTrashed()
            ->whereNotNull('fecha_ingreso')
            ->whereNotNull('fecha_baja')
            ->whereBetween('fecha_baja', [$desde->toDateString(), $hasta->toDateString()])
            ->get(['fecha_ingreso', 'fecha_baja']);

        if ($filas->isEmpty()) {
            return null;
        }

        return round($filas->avg(fn (Colaborador $c) => $c->fecha_ingreso?->diffInDays($c->fecha_baja) ?? 0) ?? 0, 1);
    }

    /**
     * @param  Collection<int, mixed>  $fechas
     */
    private function promedioDias(Collection $fechas, ?CarbonInterface $hasta): ?float
    {
        if ($fechas->isEmpty()) {
            return null;
        }

        $referencia = $hasta ?? now();

        return round((float) $fechas->avg(fn ($f) => Carbon::parse($f)->diffInDays($referencia)), 1);
    }

    /**
     * @param  Collection<int, int>|null  $sucursales
     */
    private function tiempoVacantes(?Collection $sucursales, ?int $empresaId, CarbonInterface $desde, CarbonInterface $hasta): ?float
    {
        $vacantes = $this->vacantes($sucursales, $empresaId)
            ->where('estado', EstadoVacante::Cubierta->value)
            ->whereNotNull('fecha_cierre')
            ->whereBetween('fecha_cierre', [$desde->toDateString(), $hasta->toDateString()])
            ->get(['fecha_apertura', 'fecha_cierre']);

        return $vacantes->isEmpty() ? null : round((float) $vacantes->avg(fn (Vacante $v) => $v->diasAbierta()), 1);
    }

    /**
     * @param  Collection<int, Candidato>  $contratados
     */
    private function tiempoCandidatos(Collection $contratados): ?float
    {
        $validos = $contratados->filter(fn (Candidato $c) => $c->created_at !== null && $c->contratado_en !== null);

        return $validos->isEmpty() ? null : round((float) $validos->avg(fn (Candidato $c) => $c->created_at?->diffInDays($c->contratado_en) ?? 0), 1);
    }

    /**
     * Embudo de candidatos registrados en el periodo, por etapa (orden del pipeline).
     *
     * @param  Collection<int, int>|null  $sucursales
     * @return list<array{estado: string, etiqueta: string, total: int}>
     */
    private function embudo(?Collection $sucursales, ?int $empresaId, CarbonInterface $desde, CarbonInterface $hasta): array
    {
        $conteos = $this->candidatos($sucursales, $empresaId)
            ->whereBetween('created_at', [$desde, $hasta])
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        return array_map(fn (EstadoCandidato $e) => [
            'estado' => $e->value,
            'etiqueta' => $e->etiqueta(),
            'total' => (int) ($conteos[$e->value] ?? 0),
        ], EstadoCandidato::cases());
    }
}
