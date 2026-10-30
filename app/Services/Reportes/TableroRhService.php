<?php

namespace App\Services\Reportes;

use App\Enums\EstadoCandidato;
use App\Enums\EstadoUsuario;
use App\Enums\GrupoPuestoIndicador;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Bajas\TableroRotacionService;
use App\Services\Contratos\ContratoLaboralService;
use App\Services\Headcount\HeadcountService;
use App\Services\Reclutamiento\CostoReclutamientoService;
use App\Services\Vacantes\VacantesListadoService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Tablero ejecutivo de RH ("MR. LANA PEOPLE · Tablero de Recursos Humanos").
 * Un solo DTO estable para la web y la API:
 *
 *   summary · recruitment_funnel · time_to_hire_by_level · turnover_monthly ·
 *   headcount_by_branch · filters · generated_at
 *
 * Todo se calcula con datos reales y respeta el alcance del usuario (RH
 * global ve todo; gerente/regional solo sus sucursales). Cada KPI CONSUME su
 * fuente canónica —el tablero solo presenta, nunca recalcula fórmulas propias
 * ni escribe datos—:
 *  - plantilla activa / cobertura = HeadcountService (plazas autorizadas vs
 *    ocupadas por par sucursal-puesto), la misma cifra que headcount_by_branch.
 *  - rotación del mes y tendencia = TableroRotacionService, el mismo motor de
 *    la pestaña Rotación de Bajas (bajas ÷ plantilla promedio × 100, por último
 *    día). Sin datos el valor es null → la UI lo dice, nunca 0.
 *  - costo por contratación e inversión en campañas = CostoReclutamientoService
 *    (ANSI/SHRM: gasto interno + externo / contrataciones del periodo).
 *  - contratos por vencer = ContratoLaboralService::porVencer (alcance del
 *    módulo «contratos», ventana config('contratos.dias_aviso_vencimiento')).
 *  - tiempo de contratación = postulación (alta del candidato) → contratación.
 *  - permanencia promedio = antigüedad promedio de la plantilla vigente.
 *  - embudo = personas que ALCANZARON cada etapa (etapa_maxima, autoridad de
 *    CandidatoWorkflowService), no su estado actual: un contratado cuenta
 *    también en entrevista, psicométricas...
 */
class TableroRhService
{
    public const MESES_TENDENCIA = [6, 12, 24];

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly HeadcountService $headcount,
        private readonly VacantesListadoService $vacantesListado,
        private readonly TableroRotacionService $rotacion,
        private readonly CostoReclutamientoService $costoReclutamiento,
        private readonly ContratoLaboralService $contratos,
    ) {}

    /**
     * @param  array{mes?: string|null, sucursal_id?: int|string|null, empresa_id?: int|string|null, meses?: int|string|null}  $filtros
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

        $empresaFiltro = isset($filtros['empresa_id']) && $filtros['empresa_id'] !== '' ? (int) $filtros['empresa_id'] : null;
        $meses = in_array((int) ($filtros['meses'] ?? 12), self::MESES_TENDENCIA, true) ? (int) ($filtros['meses'] ?? 12) : 12;

        // La empresa acota las sucursales visibles; una sucursal de otra
        // empresa (o fuera del alcance) se ignora.
        $deEmpresa = $empresaFiltro !== null
            ? Sucursal::query()->whereIn('id', $visibles)->where('empresa_id', $empresaFiltro)->pluck('id')->map(fn ($id) => (int) $id)
            : $visibles;

        if ($sucursalFiltro !== null && ! $deEmpresa->contains($sucursalFiltro)) {
            $sucursalFiltro = null;
        }

        /** @var Collection<int, int> $sucursales */
        $sucursales = $sucursalFiltro !== null ? collect([$sucursalFiltro]) : $deEmpresa;
        $todas = $global && $sucursalFiltro === null && $empresaFiltro === null;

        // Motor único de rotación, acotado a las sucursales que el tablero ya
        // resolvió por alcance; la misma serie alimenta el KPI y la tendencia.
        $serieRotacion = $this->rotacion->serieParaTablero($sucursales, $fin, $meses);

        return [
            'summary' => $this->resumen($usuario, $sucursales, $todas, $inicio, $fin, $this->rotacionDelMes($serieRotacion, $fin)),
            'recruitment_funnel' => $this->embudo($sucursales, $todas, $inicio, $fin),
            'time_to_hire_by_level' => $this->tiempoPorNivel($sucursales, $todas, $fin),
            'turnover_monthly' => $this->turnoverMensual($serieRotacion, $meses),
            'headcount_by_branch' => $this->headcount->resumenPorSucursal($sucursales)->values()->all(),
            'filters' => [
                'mes' => $mes->format('Y-m'),
                'periodo_etiqueta' => ucfirst($mes->settings(['locale' => 'es'])->translatedFormat('F Y')),
                'sucursal_id' => $sucursalFiltro,
                'empresa_id' => $empresaFiltro,
                'meses' => $meses,
                'sucursales' => Sucursal::query()->whereIn('id', $visibles)->orderBy('nombre')->get(['id', 'nombre', 'empresa_id'])
                    ->map(fn (Sucursal $s) => ['id' => $s->id, 'nombre' => $s->nombre, 'empresa_id' => $s->empresa_id])->values()->all(),
                'empresas' => Empresa::query()->whereIn('id', Sucursal::query()->whereIn('id', $visibles)->select('empresa_id'))->orderBy('nombre')->get(['id', 'nombre'])
                    ->map(fn (Empresa $e) => ['id' => $e->id, 'nombre' => $e->nombre])->values()->all(),
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Lo que el INICIO operativo muestra arriba: las cuatro cifras que
     * ayudan a decidir hoy (vacantes reales, contrataciones en proceso,
     * contratos por vencer según el módulo de contratos; los expedientes
     * incompletos los agrega el controlador desde DatosFaltantesService).
     * Ligero: sin embudo, tendencias ni costos — eso vive en el tablero completo.
     *
     * `plantilla` = headcount del alcance (HeadcountService::totalesGenerales,
     * la misma cifra que Organización → Sucursales): plazas autorizadas,
     * ocupadas por colaboradores vigentes y personas fuera de plantilla.
     * `vacantes` = plazas por cubrir de RH → Vacantes (misma consulta y
     * resumen que esa pantalla, VacantesListadoService).
     *
     * @return array{vacantes: int, en_proceso: int, por_vencer: int, dias_por_vencer: int, plantilla: array{autorizada: int, ocupada: int, vacantes: int, cumplimiento: float, personas_activas: int, fuera_de_plantilla: int, activos: int}}
     */
    public function inicio(User $usuario): array
    {
        $global = $this->alcance->tieneAlcanceGlobal($usuario);
        $sucursales = $this->alcance->sucursalesVisiblesIds($usuario);
        $headcount = $this->headcount->totalesGenerales($sucursales);

        return [
            'vacantes' => (int) array_sum(array_column($this->filasVacantes($usuario, $sucursales, $global), 'plazas_disponibles')),
            'plantilla' => [
                'autorizada' => $headcount['plantilla_autorizada'],
                'ocupada' => $headcount['plantilla_actual'],
                'vacantes' => $headcount['vacantes'],
                'cumplimiento' => $headcount['cumplimiento'],
                'personas_activas' => $headcount['personas_activas'],
                'fuera_de_plantilla' => $headcount['fuera_de_plantilla'],
                'activos' => $this->headcount->poblacion($global ? null : $sucursales)['total']['activos'],
            ],
            'dias_por_vencer' => $this->diasAvisoVencimiento(),
            'en_proceso' => $this->candidatos($sucursales, $global)
                ->whereNotIn('estado', array_map(fn (EstadoCandidato $e) => $e->value, array_filter(EstadoCandidato::cases(), fn (EstadoCandidato $e) => $e->esTerminal())))
                ->count(),
            // Mismo criterio que el módulo de contratos (ContratoLaboralService,
            // alcance del módulo) y la ventana configurable; no se hardcodean 30
            // días ni el tipo de contrato.
            'por_vencer' => $this->contratos->porVencer($usuario, $this->diasAvisoVencimiento())->count(),
        ];
    }

    /**
     * @param  Collection<int, int>  $sucursales
     * @param  array{porcentaje: float|null, bajas: int, plantilla_promedio: float|null, semaforo: string|null}  $rotacionMes
     * @return array<string, mixed>
     */
    private function resumen(User $usuario, Collection $sucursales, bool $todas, CarbonImmutable $inicio, CarbonImmutable $fin, array $rotacionMes): array
    {
        // Plantilla y cobertura salen de la fuente canónica (HeadcountService):
        // el número grande = plazas autorizadas ocupadas, igual que la tabla
        // headcount_by_branch; así la tarjeta ya no se contradice consigo misma.
        $cobertura = $this->headcount->totalesGenerales($sucursales);

        // Vacantes = plazas por cubrir de RH → Vacantes (misma consulta y
        // regla que esa pantalla), acotadas a las sucursales del tablero.
        $corporativas = Sucursal::query()->whereIn('id', $sucursales)->where('es_corporativo', true)->pluck('id')->map(fn ($id) => (int) $id);
        $filasVacantes = collect($this->filasVacantes($usuario, $sucursales, $todas));
        $vacantesCorporativo = (int) $filasVacantes->filter(fn (array $f): bool => $corporativas->contains((int) ($f['sucursal_id'] ?? 0)))->sum('plazas_disponibles');
        $vacantesSucursales = (int) $filasVacantes->sum('plazas_disponibles') - $vacantesCorporativo;

        // Costo por contratación e inversión en campañas: el módulo canónico
        // (CostoReclutamientoService, ANSI/SHRM), scopeado a las sucursales del
        // tablero. La inversión del mes = su gasto_total.
        $filtrosCosto = $todas ? [] : ['sucursal_ids' => array_values($sucursales->map(fn (int|string $id): int => (int) $id)->all())];
        $costo = $this->costoReclutamiento->resumen($inicio, $fin, $filtrosCosto);

        $contratados = $this->candidatos($sucursales, $todas)
            ->where('estado', EstadoCandidato::Contratado->value)
            ->whereBetween('contratado_en', [$inicio, $fin])
            ->get(['created_at', 'contratado_en']);
        $dias = $contratados->map(fn (Candidato $c) => $c->created_at !== null && $c->contratado_en !== null ? $c->created_at->diffInDays($c->contratado_en) : null)->filter(fn ($d) => $d !== null);

        // Plantilla vigente = mismo criterio que headcount (activo / en
        // incorporación); una baja nunca cuenta.
        $antiguedades = $this->colaboradores($sucursales)
            ->whereIn('estatus', EstadoUsuario::valoresVigentes())
            ->whereNotNull('fecha_ingreso')
            ->pluck('fecha_ingreso')
            ->map(fn ($f) => CarbonImmutable::parse($f)->diffInMonths(now()));

        // Contratos por vencer: criterio del módulo de contratos (alcance +
        // ventana configurable), intersectado con las sucursales del tablero
        // para honrar el filtro de empresa/sucursal.
        $diasAviso = $this->diasAvisoVencimiento();
        $porVencer = $this->contratos->porVencer($usuario, $diasAviso)
            ->filter(fn (ContratoLaboral $c): bool => $sucursales->contains($c->colaborador->sucursal_principal_id))
            ->count();

        return [
            'plantilla_activa' => [
                'valor' => $cobertura['plantilla_actual'],
                'autorizada' => $cobertura['plantilla_autorizada'],
                'porcentaje' => $cobertura['cumplimiento'],
                'vacantes' => $cobertura['vacantes'],
                'personas_activas' => $cobertura['personas_activas'],
                'fuera_de_plantilla' => $cobertura['fuera_de_plantilla'],
                // Fuente única: Personas activas y desglose Corporativo/Sucursales
                // iguales a los del Directorio y Reportes.
                'poblacion' => $this->headcount->poblacion($todas ? null : $sucursales),
            ],
            'vacantes_abiertas' => ['valor' => $vacantesSucursales + $vacantesCorporativo, 'sucursales' => $vacantesSucursales, 'corporativo' => $vacantesCorporativo],
            'rotacion_mes' => ['porcentaje' => $rotacionMes['porcentaje'], 'bajas' => $rotacionMes['bajas'], 'plantilla_promedio' => $rotacionMes['plantilla_promedio'], 'semaforo' => $rotacionMes['semaforo']],
            'costo_por_contratacion' => ['valor' => $costo['costo_por_contratacion'], 'inversion' => $costo['gasto_total'], 'contratados' => $costo['contrataciones']],
            'tiempo_contratacion' => ['dias' => $dias->isEmpty() ? null : round((float) $dias->avg(), 1), 'contratados' => $contratados->count()],
            'permanencia_promedio' => ['meses' => $antiguedades->isEmpty() ? null : round((float) $antiguedades->avg(), 1), 'colaboradores' => $antiguedades->count()],
            'contratos_por_vencer' => ['valor' => $porVencer, 'dias' => $diasAviso],
            'inversion_campanas_mes' => ['valor' => $costo['gasto_total'], 'campanas' => $costo['campanas']],
        ];
    }

    /**
     * Filas de RH → Vacantes (VacantesListadoService::consulta + filas, el
     * mismo camino que esa pantalla, con su alcance del módulo «vacantes»)
     * acotadas a las sucursales del tablero. Con alcance global y sin filtro
     * también entran las vacantes sin sucursal, igual que en la pantalla.
     *
     * @param  Collection<int, int>  $sucursales
     * @return list<array<string, mixed>>
     */
    private function filasVacantes(User $usuario, Collection $sucursales, bool $todas): array
    {
        $filas = $this->vacantesListado->filas($this->vacantesListado->consulta($usuario)->get());

        if ($todas) {
            return $filas;
        }

        $ids = $sucursales->map(fn (int|string $id): int => (int) $id);

        return array_values(array_filter($filas, fn (array $f): bool => $ids->contains((int) ($f['sucursal_id'] ?? 0))));
    }

    private function diasAvisoVencimiento(): int
    {
        return max(1, (int) config('contratos.dias_aviso_vencimiento', 15));
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
     * Tendencia mensual para la gráfica. `indice` es null en los meses sin datos
     * → el front dibuja hueco, nunca 0%.
     *
     * @param  list<array<string, mixed>>  $serie
     * @return list<array<string, mixed>>
     */
    private function turnoverMensual(array $serie, int $meses): array
    {
        return array_map(function (array $fila) use ($meses): array {
            $mes = CarbonImmutable::createFromFormat('!Y-m', (string) $fila['periodo']) ?: CarbonImmutable::now();

            return [
                'mes' => $fila['periodo'],
                'etiqueta' => ucfirst($mes->settings(['locale' => 'es'])->translatedFormat($meses > 12 ? 'M y' : 'M')),
                'bajas' => (int) $fila['bajas'],
                'plantilla_promedio' => $fila['plantilla_promedio'],
                'indice' => $fila['indice'],
                'en_curso' => $fila['en_curso'],
                'meta' => $fila['meta'],
                'semaforo' => $fila['semaforo'],
            ];
        }, $serie);
    }

    /**
     * KPI «Rotación del mes»: el índice del mes seleccionado (último de la serie).
     *
     * @param  list<array<string, mixed>>  $serie
     * @return array{porcentaje: float|null, bajas: int, plantilla_promedio: float|null, semaforo: string|null}
     */
    private function rotacionDelMes(array $serie, CarbonImmutable $mes): array
    {
        $clave = $mes->format('Y-m');

        foreach ($serie as $fila) {
            if ($fila['periodo'] === $clave) {
                return ['porcentaje' => $fila['indice'], 'bajas' => (int) $fila['bajas'], 'plantilla_promedio' => $fila['plantilla_promedio'], 'semaforo' => $fila['semaforo']];
            }
        }

        return ['porcentaje' => null, 'bajas' => 0, 'plantilla_promedio' => null, 'semaforo' => null];
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
