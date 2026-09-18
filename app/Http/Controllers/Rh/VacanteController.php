<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EstadoCandidato;
use App\Enums\EstadoVacante;
use App\Exports\ReporteRhExport;
use App\Http\Controllers\Controller;
use App\Models\Candidato;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\HeadcountTarget;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vacante;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Headcount\HeadcountService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Vacantes es 100% informativo: cada fila es una combinación
 * (sucursal, puesto) con HeadcountTarget vigente, nunca un registro que RH
 * captura o mueve a mano (ver docs/HEADCOUNT_Y_VACANTES.md). "Plantilla
 * cubierta" y las demás cifras se calculan en vivo a partir de
 * HeadcountService y de las filas `vacantes` que
 * App\Services\Vacantes\VacanteAutoGenerationService sincroniza solo, para
 * costo presupuestado y fecha de apertura más antigua.
 *
 * @phpstan-type FilaVacante array{
 *     id: string,
 *     empresa_id: int|null,
 *     sucursal: array{id: int, nombre: string}|null,
 *     departamento: array{id: int, nombre: string}|null,
 *     puesto: array{id: int, nombre: string}|null,
 *     plantilla_permitida: int,
 *     plantilla_cubierta: int,
 *     vacantes_disponibles: int,
 *     candidatos_activos: int,
 *     candidatos_finalistas: int,
 *     cobertura_pct: float,
 *     costo_presupuestado_mensual: float|null,
 *     fecha_apertura_mas_antigua: string|null,
 * }
 */
class VacanteController extends Controller
{
    private const FILTROS = ['empresa_id', 'sucursal_id', 'departamento_id', 'puesto_id', 'busqueda'];

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly HeadcountService $headcount,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Vacante::class);

        $filasBase = $this->filasPlantilla($request->user());
        $filasFiltradas = $this->aplicarFiltros($filasBase, $request);

        return Inertia::render('Rh/Vacantes/Index', [
            'vacantes' => $filasFiltradas->values(),
            'kpis' => $this->kpis($filasBase),
            'filtros' => $request->only(self::FILTROS),
            'opciones' => [
                'empresas' => Empresa::query()->orderBy('nombre')->get(['id', 'nombre']),
                'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre', 'empresa_id']),
                'departamentos' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
                'puestos' => Puesto::query()->orderBy('nombre')->get(['id', 'nombre', 'departamento_id']),
            ],
        ]);
    }

    public function exportarExcel(Request $request): HttpResponse
    {
        $this->authorize('viewAny', Vacante::class);

        [$columnas, $filas] = $this->tabla($request);

        return Excel::download(
            new ReporteRhExport('Vacantes', $columnas, $filas),
            'vacantes-'.now()->format('Y-m-d').'.xlsx',
        );
    }

    public function exportarPdf(Request $request): HttpResponse
    {
        $this->authorize('viewAny', Vacante::class);

        [$columnas, $filas] = $this->tabla($request);

        return Pdf::loadView('pdf.reporte-rh', ['titulo' => 'Vacantes', 'columnas' => $columnas, 'filas' => $filas])
            ->setPaper('letter', 'landscape')
            ->download('vacantes-'.now()->format('Y-m-d').'.pdf');
    }

    /**
     * KPIs de cabecera: reflejan el estado general acotado por alcance
     * organizacional, no los filtros activos en pantalla — para eso están
     * las columnas y la exportación (mismo criterio que el resto de
     * tableros de RH).
     *
     * @param  Collection<int, FilaVacante>  $filas
     * @return array<string, int|float>
     */
    private function kpis(Collection $filas): array
    {
        $permitidaTotal = (int) $filas->sum('plantilla_permitida');
        $cubiertaTotal = (int) $filas->sum('plantilla_cubierta');

        return [
            'sucursales_bajo_cobertura' => $filas->filter(fn (array $f) => $f['vacantes_disponibles'] > 0)->count(),
            'plantilla_permitida_total' => $permitidaTotal,
            'plantilla_cubierta_total' => $cubiertaTotal,
            'vacantes_totales' => (int) $filas->sum('vacantes_disponibles'),
            'cobertura_pct_global' => $permitidaTotal > 0 ? round(($cubiertaTotal / $permitidaTotal) * 100, 1) : 0.0,
            'costo_mensual_total' => (float) $filas->sum(fn (array $f) => $f['costo_presupuestado_mensual'] ?? 0.0),
        ];
    }

    /**
     * Una fila por cada (sucursal, puesto) con HeadcountTarget vigente
     * (universo de App\Services\Headcount\HeadcountService::paresConTarget()),
     * acotada por el alcance organizacional del usuario — nunca por los
     * filtros de pantalla, ver kpis().
     *
     * @return Collection<int, FilaVacante>
     */
    private function filasPlantilla(User $usuario): Collection
    {
        $pares = $this->headcount->paresConTarget();

        $targetsPorPar = HeadcountTarget::query()
            ->with(['sucursal:id,nombre,empresa_id', 'departamento:id,nombre', 'puesto:id,nombre'])
            ->get()
            ->keyBy(fn (HeadcountTarget $t) => sprintf('%d:%d', $t->sucursal_id, $t->puesto_id));

        $actualPorPar = $this->headcount->plantillaActualPorSucursalPuesto();

        $vacantesPorPar = Vacante::query()
            ->whereNotNull('sucursal_id')
            ->whereNotNull('puesto_id')
            ->get(['sucursal_id', 'puesto_id', 'estado', 'sueldo_mensual', 'fecha_apertura'])
            ->groupBy(fn (Vacante $v) => sprintf('%d:%d', $v->sucursal_id, $v->puesto_id));

        $candidatosPorPar = Candidato::query()
            ->whereNotNull('sucursal_id')
            ->whereNotNull('puesto_objetivo_id')
            ->get(['sucursal_id', 'puesto_objetivo_id', 'estado'])
            ->groupBy(fn (Candidato $c) => sprintf('%d:%d', $c->sucursal_id, $c->puesto_objetivo_id));

        // Última fase no terminal del pipeline de candidatos, calculada en
        // vivo a partir del enum (nunca por nombre de estado): así este
        // controlador no depende de cómo se llamen las fases hoy.
        $ultimaFaseNoTerminal = collect(EstadoCandidato::cases())
            ->filter(fn (EstadoCandidato $e) => ! $e->esTerminal())
            ->sortByDesc(fn (EstadoCandidato $e) => $e->orden())
            ->first();

        $sucursalesVisibles = $this->alcance->tieneAlcanceGlobal($usuario)
            ? null
            : $this->alcance->sucursalesVisiblesIds($usuario);

        return $pares
            ->map(fn (array $par) => $targetsPorPar->get(sprintf('%d:%d', $par['sucursal_id'], $par['puesto_id'])))
            ->filter()
            ->filter(fn (HeadcountTarget $target) => $sucursalesVisibles === null || $sucursalesVisibles->contains($target->sucursal_id))
            ->map(function (HeadcountTarget $target) use ($actualPorPar, $vacantesPorPar, $candidatosPorPar, $ultimaFaseNoTerminal) {
                $clave = sprintf('%d:%d', $target->sucursal_id, $target->puesto_id);

                $permitida = (int) $target->plantilla_autorizada;
                $cubierta = (int) ($actualPorPar[$clave] ?? 0);

                $vacantesFilas = $vacantesPorPar->get($clave);
                $costoPresupuestado = $vacantesFilas !== null
                    ? (float) $vacantesFilas->sum(fn (Vacante $v) => (float) ($v->sueldo_mensual ?? 0))
                    : null;

                $vacantesAbiertas = $vacantesFilas?->filter(
                    fn (Vacante $v) => ! in_array($v->estado, [EstadoVacante::Cubierta, EstadoVacante::Cancelada], true)
                );
                $fechaMasAntigua = $vacantesAbiertas !== null && $vacantesAbiertas->isNotEmpty()
                    ? $vacantesAbiertas->min('fecha_apertura')
                    : null;

                $candidatos = $candidatosPorPar->get($clave, collect());
                $candidatosActivos = $candidatos->filter(fn (Candidato $c) => ! $c->estado->esTerminal())->count();
                $candidatosFinalistas = $candidatos->filter(fn (Candidato $c) => $c->estado === $ultimaFaseNoTerminal)->count();

                return [
                    'id' => $clave,
                    'empresa_id' => $target->sucursal?->empresa_id,
                    'sucursal' => $target->sucursal ? ['id' => $target->sucursal->id, 'nombre' => $target->sucursal->nombre] : null,
                    'departamento' => $target->departamento ? ['id' => $target->departamento->id, 'nombre' => $target->departamento->nombre] : null,
                    'puesto' => $target->puesto ? ['id' => $target->puesto->id, 'nombre' => $target->puesto->nombre] : null,
                    'plantilla_permitida' => $permitida,
                    'plantilla_cubierta' => $cubierta,
                    'vacantes_disponibles' => max($permitida - $cubierta, 0),
                    'candidatos_activos' => $candidatosActivos,
                    'candidatos_finalistas' => $candidatosFinalistas,
                    'cobertura_pct' => $permitida > 0 ? round(($cubierta / $permitida) * 100, 1) : 0.0,
                    'costo_presupuestado_mensual' => $costoPresupuestado,
                    'fecha_apertura_mas_antigua' => $fechaMasAntigua?->toDateString(),
                ];
            })
            ->sort(fn (array $a, array $b) => [$a['sucursal']['nombre'] ?? '', $a['puesto']['nombre'] ?? '']
                <=> [$b['sucursal']['nombre'] ?? '', $b['puesto']['nombre'] ?? ''])
            ->values();
    }

    /**
     * @param  Collection<int, FilaVacante>  $filas
     * @return Collection<int, FilaVacante>
     */
    private function aplicarFiltros(Collection $filas, Request $request): Collection
    {
        $empresaId = $request->integer('empresa_id') ?: null;
        $sucursalId = $request->integer('sucursal_id') ?: null;
        $departamentoId = $request->integer('departamento_id') ?: null;
        $puestoId = $request->integer('puesto_id') ?: null;
        $busqueda = mb_strtolower(trim($request->string('busqueda')->toString()));

        if ($empresaId !== null) {
            $filas = $filas->filter(fn (array $f) => $f['empresa_id'] === $empresaId);
        }

        if ($sucursalId !== null) {
            $filas = $filas->filter(fn (array $f) => ($f['sucursal']['id'] ?? null) === $sucursalId);
        }

        if ($departamentoId !== null) {
            $filas = $filas->filter(fn (array $f) => ($f['departamento']['id'] ?? null) === $departamentoId);
        }

        if ($puestoId !== null) {
            $filas = $filas->filter(fn (array $f) => ($f['puesto']['id'] ?? null) === $puestoId);
        }

        if ($busqueda !== '') {
            $filas = $filas->filter(
                fn (array $f) => str_contains(mb_strtolower((string) ($f['puesto']['nombre'] ?? '')), $busqueda)
                    || str_contains(mb_strtolower((string) ($f['departamento']['nombre'] ?? '')), $busqueda)
                    || str_contains(mb_strtolower((string) ($f['sucursal']['nombre'] ?? '')), $busqueda)
            );
        }

        return $filas;
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<int, string|int|float|null>>}
     */
    private function tabla(Request $request): array
    {
        $filas = $this->aplicarFiltros($this->filasPlantilla($request->user()), $request);

        $columnas = [
            'Sucursal', 'Departamento', 'Puesto', 'Plantilla permitida', 'Plantilla cubierta',
            'Vacantes disponibles', 'Candidatos activos', 'Candidatos finalistas', 'Cobertura %',
            'Costo mensual', 'Fecha faltante más antigua',
        ];

        $filasTabla = $filas->map(fn (array $f) => [
            $f['sucursal']['nombre'] ?? null,
            $f['departamento']['nombre'] ?? null,
            $f['puesto']['nombre'] ?? null,
            $f['plantilla_permitida'],
            $f['plantilla_cubierta'],
            $f['vacantes_disponibles'],
            $f['candidatos_activos'],
            $f['candidatos_finalistas'],
            $f['cobertura_pct'],
            $f['costo_presupuestado_mensual'],
            $f['fecha_apertura_mas_antigua'],
        ])->all();

        if ($filas->isNotEmpty()) {
            $permitidaTotal = (int) $filas->sum('plantilla_permitida');
            $cubiertaTotal = (int) $filas->sum('plantilla_cubierta');

            $filasTabla[] = [
                'Total', null, null,
                $permitidaTotal,
                $cubiertaTotal,
                (int) $filas->sum('vacantes_disponibles'),
                (int) $filas->sum('candidatos_activos'),
                (int) $filas->sum('candidatos_finalistas'),
                $permitidaTotal > 0 ? round(($cubiertaTotal / $permitidaTotal) * 100, 1) : 0.0,
                (float) $filas->sum(fn (array $f) => $f['costo_presupuestado_mensual'] ?? 0.0),
                null,
            ];
        }

        return [$columnas, $filasTabla];
    }
}
