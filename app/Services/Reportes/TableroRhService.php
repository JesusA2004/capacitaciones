<?php

namespace App\Services\Reportes;

use App\Enums\EstadoCandidato;
use App\Enums\EstadoContratoLaboral;
use App\Enums\EstadoUsuario;
use App\Enums\EstadoVacante;
use App\Enums\GrupoPuestoIndicador;
use App\Enums\TipoContratacion;
use App\Models\CampanaReclutamiento;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\HeadcountTarget;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vacante;
use App\Services\AlcanceOrganizacionalService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Tablero ejecutivo de RH ("MR. LANA PEOPLE · Tablero de Recursos Humanos").
 * Un solo DTO estable para la web y la API:
 *
 *   summary · recruitment_funnel · time_to_hire_by_level · turnover_monthly · filters · generated_at
 *
 * Todo se calcula con datos reales y respeta el alcance del usuario (RH
 * global ve todo; gerente/regional solo sus sucursales). Fórmulas:
 *  - plantilla activa = colaboradores vigentes / plantilla autorizada (headcount)
 *  - rotación del mes = bajas del mes / plantilla promedio (inicio+fin)/2
 *  - costo por contratación = inversión en campañas del mes / contratados del mes
 *  - tiempo de contratación = postulación (alta del candidato) → contratación
 *  - permanencia promedio = antigüedad promedio de la plantilla vigente
 *  - contratos por vencer = periodos de prueba que terminan en 30 días
 *  - embudo = personas que ALCANZARON cada etapa (hito máximo), no su estado
 *    actual: un contratado cuenta también en entrevista, psicométricas...
 */
class TableroRhService
{
    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    /**
     * @param  array{mes?: string|null, sucursal_id?: int|string|null}  $filtros
     * @return array<string, mixed>
     */
    public function construir(User $usuario, array $filtros = []): array
    {
        $mes = isset($filtros['mes']) && preg_match('/^\d{4}-\d{2}$/', (string) $filtros['mes']) === 1
            ? CarbonImmutable::parse($filtros['mes'].'-01')
            : CarbonImmutable::now()->startOfMonth();
        $inicio = $mes->startOfMonth();
        $fin = $mes->endOfMonth();
        $global = $this->alcance->tieneAlcanceGlobal($usuario);
        $visibles = $this->alcance->sucursalesVisiblesIds($usuario);
        $sucursalFiltro = isset($filtros['sucursal_id']) && $filtros['sucursal_id'] !== '' ? (int) $filtros['sucursal_id'] : null;

        if ($sucursalFiltro !== null && ! $visibles->contains($sucursalFiltro)) {
            $sucursalFiltro = null;
        }

        /** @var Collection<int, int> $sucursales */
        $sucursales = $sucursalFiltro !== null ? collect([$sucursalFiltro]) : $visibles;
        $todas = $global && $sucursalFiltro === null;

        return [
            'summary' => $this->resumen($sucursales, $todas, $inicio, $fin),
            'recruitment_funnel' => $this->embudo($sucursales, $todas, $inicio, $fin),
            'time_to_hire_by_level' => $this->tiempoPorNivel($sucursales, $todas, $fin),
            'turnover_monthly' => $this->rotacionMensual($sucursales, $fin),
            'filters' => [
                'mes' => $mes->format('Y-m'),
                'periodo_etiqueta' => ucfirst($mes->settings(['locale' => 'es'])->translatedFormat('F Y')),
                'sucursal_id' => $sucursalFiltro,
                'sucursales' => Sucursal::query()->whereIn('id', $visibles)->orderBy('nombre')->get(['id', 'nombre'])
                    ->map(fn (Sucursal $s) => ['id' => $s->id, 'nombre' => $s->nombre])->values()->all(),
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, int>  $sucursales
     * @return array<string, mixed>
     */
    private function resumen(Collection $sucursales, bool $todas, CarbonImmutable $inicio, CarbonImmutable $fin): array
    {
        $activos = $this->colaboradores($sucursales)->where('estatus', EstadoUsuario::Activo->value)->count();
        $autorizada = (int) HeadcountTarget::query()->whereIn('sucursal_id', $sucursales)->sum('plantilla_autorizada');

        $corporativas = Sucursal::query()->whereIn('id', $sucursales)->where('es_corporativo', true)->pluck('id');
        $vacantes = Vacante::query()
            ->whereIn('sucursal_id', $sucursales)
            ->whereNotIn('estado', [EstadoVacante::Cubierta->value, EstadoVacante::Cancelada->value])
            ->get(['sucursal_id', 'plazas_disponibles']);
        $plazas = fn (Collection $v): int => (int) $v->sum(fn (Vacante $x) => max(1, (int) $x->plazas_disponibles));
        $vacantesCorporativo = $plazas($vacantes->filter(fn (Vacante $v) => $corporativas->contains($v->sucursal_id)));
        $vacantesSucursales = $plazas($vacantes) - $vacantesCorporativo;

        $bajas = $this->bajasEntre($sucursales, $inicio, $fin);
        $promedio = ($this->plantillaAl($sucursales, $inicio->subDay()) + $this->plantillaAl($sucursales, $fin)) / 2;

        $campanas = CampanaReclutamiento::query()
            ->where('anio', $inicio->year)
            ->where('mes', $inicio->month)
            ->when(! $todas, fn (Builder $q) => $q->whereIn('sucursal_id', $sucursales))
            ->get(['monto']);
        $inversion = (float) $campanas->sum(fn (CampanaReclutamiento $c) => (float) $c->monto);

        $contratados = $this->candidatos($sucursales, $todas)
            ->where('estado', EstadoCandidato::Contratado->value)
            ->whereBetween('contratado_en', [$inicio, $fin])
            ->get(['created_at', 'contratado_en']);
        $dias = $contratados->map(fn (Candidato $c) => $c->created_at !== null && $c->contratado_en !== null ? $c->created_at->diffInDays($c->contratado_en) : null)->filter(fn ($d) => $d !== null);

        $antiguedades = $this->colaboradores($sucursales)
            ->where('estatus', EstadoUsuario::Activo->value)
            ->whereNotNull('fecha_ingreso')
            ->pluck('fecha_ingreso')
            ->map(fn ($f) => CarbonImmutable::parse($f)->diffInMonths(now()));

        $porVencer = ContratoLaboral::query()
            ->where('estado', EstadoContratoLaboral::Vigente->value)
            ->whereIn('tipo', [TipoContratacion::PeriodoPrueba->value, TipoContratacion::CapacitacionInicial->value])
            ->whereBetween('fecha_fin', [now()->toDateString(), now()->addDays(30)->toDateString()])
            ->whereIn('colaborador_id', $this->colaboradores($sucursales)->select('id'))
            ->count();

        return [
            'plantilla_activa' => ['valor' => $activos, 'autorizada' => $autorizada, 'porcentaje' => $autorizada > 0 ? round($activos / $autorizada * 100, 1) : 0.0],
            'vacantes_abiertas' => ['valor' => $vacantesSucursales + $vacantesCorporativo, 'sucursales' => $vacantesSucursales, 'corporativo' => $vacantesCorporativo],
            'rotacion_mes' => ['porcentaje' => $promedio > 0 ? round($bajas / $promedio * 100, 1) : 0.0, 'bajas' => $bajas, 'plantilla_promedio' => round($promedio, 1)],
            'costo_por_contratacion' => ['valor' => $contratados->count() > 0 ? round($inversion / $contratados->count(), 2) : null, 'inversion' => $inversion, 'contratados' => $contratados->count()],
            'tiempo_contratacion' => ['dias' => $dias->isEmpty() ? null : round((float) $dias->avg(), 1), 'contratados' => $contratados->count()],
            'permanencia_promedio' => ['meses' => $antiguedades->isEmpty() ? null : round((float) $antiguedades->avg(), 1), 'colaboradores' => $antiguedades->count()],
            'contratos_por_vencer' => ['valor' => $porVencer, 'dias' => 30],
            'inversion_campanas_mes' => ['valor' => $inversion, 'campanas' => $campanas->count()],
        ];
    }

    /**
     * Personas (candidatos que se postularon en el mes) que alcanzaron cada
     * etapa, según su hito máximo y los registros estructurados.
     *
     * @param  Collection<int, int>  $sucursales
     * @return list<array{clave: string, etiqueta: string, total: int}>
     */
    private function embudo(Collection $sucursales, bool $todas, CarbonImmutable $inicio, CarbonImmutable $fin): array
    {
        $base = fn () => $this->candidatos($sucursales, $todas)->whereBetween('created_at', [$inicio, $fin]);
        $alcanzo = fn (int $orden) => $base()->where('etapa_maxima', '>=', $orden)->count();

        return [
            ['clave' => 'interesados', 'etiqueta' => 'Interesados', 'total' => $base()->count()],
            ['clave' => 'viables', 'etiqueta' => 'Viables', 'total' => $alcanzo(EstadoCandidato::EntrevistaPendiente->orden())],
            ['clave' => 'entrevista', 'etiqueta' => 'Entrevista', 'total' => $base()->where(fn (Builder $q) => $q->where('etapa_maxima', '>=', EstadoCandidato::PsicometricasPendientes->orden())->orWhereHas('entrevistas'))->count()],
            ['clave' => 'psicometricas', 'etiqueta' => 'Psicométricas', 'total' => $alcanzo(EstadoCandidato::RevisionPsicometricas->orden())],
            ['clave' => 'socioeconomico', 'etiqueta' => 'Socioeconómico', 'total' => $base()->where(fn (Builder $q) => $q->where('etapa_maxima', '>=', EstadoCandidato::ReferenciasPendientes->orden())->orWhereHas('socioeconomicos'))->count()],
            ['clave' => 'referencias', 'etiqueta' => 'Referencias', 'total' => $alcanzo(EstadoCandidato::PreseleccionGerente->orden())],
            ['clave' => 'contratados', 'etiqueta' => 'Contratados', 'total' => $base()->where('estado', EstadoCandidato::Contratado->value)->count()],
        ];
    }

    /**
     * Promedio real de días postulación → contratación por grupo de puesto
     * (puestos.grupo_indicador), últimos 12 meses al corte.
     *
     * @param  Collection<int, int>  $sucursales
     * @return list<array{clave: string, etiqueta: string, dias: float|null, contratados: int}>
     */
    private function tiempoPorNivel(Collection $sucursales, bool $todas, CarbonImmutable $fin): array
    {
        $contratados = $this->candidatos($sucursales, $todas)
            ->where('estado', EstadoCandidato::Contratado->value)
            ->whereBetween('contratado_en', [$fin->subMonths(12)->startOfMonth(), $fin])
            ->with('puestoObjetivo:id,grupo_indicador')
            ->get(['id', 'puesto_objetivo_id', 'created_at', 'contratado_en']);

        $resultado = [];

        foreach ([GrupoPuestoIndicador::Gestores, GrupoPuestoIndicador::Coordinadoras, GrupoPuestoIndicador::GerenciaSucursal, GrupoPuestoIndicador::Regionales, GrupoPuestoIndicador::DireccionComercial] as $grupo) {
            $dias = $contratados
                ->filter(fn (Candidato $c) => $c->puestoObjetivo?->grupo_indicador === $grupo)
                ->map(fn (Candidato $c) => $c->created_at !== null && $c->contratado_en !== null ? $c->created_at->diffInDays($c->contratado_en) : null)
                ->filter(fn ($d) => $d !== null);

            $resultado[] = [
                'clave' => $grupo->value,
                'etiqueta' => $grupo->etiqueta(),
                'dias' => $dias->isEmpty() ? null : round((float) $dias->avg(), 1),
                'contratados' => $dias->count(),
            ];
        }

        return $resultado;
    }

    /**
     * @param  Collection<int, int>  $sucursales
     * @return list<array{mes: string, etiqueta: string, bajas: int, plantilla_promedio: float, porcentaje: float}>
     */
    private function rotacionMensual(Collection $sucursales, CarbonImmutable $fin): array
    {
        $meses = [];

        for ($i = 11; $i >= 0; $i--) {
            $mes = $fin->startOfMonth()->subMonthsNoOverflow($i);
            $bajas = $this->bajasEntre($sucursales, $mes->startOfMonth(), $mes->endOfMonth());
            $promedio = ($this->plantillaAl($sucursales, $mes->startOfMonth()->subDay()) + $this->plantillaAl($sucursales, $mes->endOfMonth())) / 2;

            $meses[] = [
                'mes' => $mes->format('Y-m'),
                'etiqueta' => ucfirst($mes->settings(['locale' => 'es'])->translatedFormat('M')),
                'bajas' => $bajas,
                'plantilla_promedio' => round($promedio, 1),
                'porcentaje' => $promedio > 0 ? round($bajas / $promedio * 100, 1) : 0.0,
            ];
        }

        return $meses;
    }

    /**
     * @param  Collection<int, int>  $sucursales
     */
    private function bajasEntre(Collection $sucursales, CarbonInterface $desde, CarbonInterface $hasta): int
    {
        return Colaborador::withTrashed()
            ->whereIn('sucursal_principal_id', $sucursales)
            ->whereBetween('fecha_baja', [$desde->toDateString(), $hasta->toDateString()])
            ->count();
    }

    /**
     * Plantilla vigente a una fecha: ingresó en o antes y no tenía baja.
     *
     * @param  Collection<int, int>  $sucursales
     */
    private function plantillaAl(Collection $sucursales, CarbonInterface $fecha): int
    {
        return Colaborador::withTrashed()
            ->whereIn('sucursal_principal_id', $sucursales)
            ->whereNotNull('fecha_ingreso')
            ->whereDate('fecha_ingreso', '<=', $fecha->toDateString())
            ->where(fn (Builder $q) => $q->whereNull('fecha_baja')->orWhereDate('fecha_baja', '>', $fecha->toDateString()))
            ->count();
    }

    /**
     * @param  Collection<int, int>  $sucursales
     * @return Builder<Colaborador>
     */
    private function colaboradores(Collection $sucursales): Builder
    {
        return Colaborador::query()->whereIn('sucursal_principal_id', $sucursales);
    }

    /**
     * Candidatos del alcance (RH global también ve los que aún no tienen
     * sucursal asignada).
     *
     * @param  Collection<int, int>  $sucursales
     * @return Builder<Candidato>
     */
    private function candidatos(Collection $sucursales, bool $todas): Builder
    {
        return Candidato::query()->where(fn (Builder $q) => $todas
            ? $q->whereIn('sucursal_id', $sucursales)->orWhereNull('sucursal_id')
            : $q->whereIn('sucursal_id', $sucursales));
    }
}
