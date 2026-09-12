<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EstadoUsuario;
use App\Enums\EstadoVacante;
use App\Enums\MotivoVacante;
use App\Exports\ReporteRhExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\ActualizarEstadoVacanteRequest;
use App\Http\Requests\Rh\CubrirVacanteRequest;
use App\Http\Requests\Rh\StoreVacanteRequest;
use App\Http\Requests\Rh\UpdateVacanteRequest;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\HeadcountTarget;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vacante;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Headcount\HeadcountService;
use App\Services\MovimientosLaborales\MovimientoLaboralService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class VacanteController extends Controller
{
    private const FILTROS = ['empresa_id', 'sucursal_id', 'departamento_id', 'puesto_id', 'responsable_rh_id', 'estado', 'busqueda', 'fecha_inicio', 'fecha_fin'];

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly MovimientoLaboralService $movimientos,
        private readonly HeadcountService $headcount,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Vacante::class);

        $vacantes = $this->queryFiltrada($request)->orderByDesc('fecha_apertura')->get();
        $this->anotarPlantilla($vacantes);

        return Inertia::render('Rh/Vacantes/Index', [
            'vacantes' => $vacantes,
            'kpis' => $this->kpis($request->user()),
            'filtros' => $request->only(self::FILTROS),
            'opciones' => [
                'empresas' => Empresa::query()->orderBy('nombre')->get(['id', 'nombre']),
                'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre', 'empresa_id']),
                'departamentos' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
                'puestos' => Puesto::query()->orderBy('nombre')->get(['id', 'nombre', 'departamento_id']),
                'responsables' => User::query()->role(['rh_admin', 'rh_auxiliar'])->orderBy('name')->get(['id', 'name', 'apellidos']),
                'motivos' => array_map(fn (MotivoVacante $m) => ['value' => $m->value, 'etiqueta' => $m->etiqueta()], MotivoVacante::cases()),
                'estados' => array_map(fn (EstadoVacante $e) => ['value' => $e->value, 'etiqueta' => $e->etiqueta()], EstadoVacante::cases()),
                'colaboradores' => User::query()
                    ->where('estatus', EstadoUsuario::Activo->value)
                    ->orderBy('name')
                    ->get(['id', 'name', 'apellidos', 'puesto_id'])
                    ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'apellidos' => $u->apellidos, 'puesto_id' => $u->puesto_id]),
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
     * KPIs del tablero: reflejan el estado general (acotado por alcance
     * organizacional), no los filtros activos en pantalla — para eso están
     * las columnas y la exportación.
     *
     * @return array<string, int>
     */
    private function kpis(User $usuario): array
    {
        $vacantes = $this->alcance->limitarPorSucursal(Vacante::query(), $usuario)->get([
            'estado', 'generada_automaticamente', 'plazas_disponibles', 'updated_at',
        ]);

        $abiertas = $vacantes->whereNotIn('estado', [EstadoVacante::Cubierta, EstadoVacante::Cancelada]);

        return [
            'vacantes_abiertas' => $abiertas->count(),
            'plazas_disponibles' => (int) $abiertas->sum('plazas_disponibles'),
            'vacantes_automaticas' => $abiertas->where('generada_automaticamente', true)->count(),
            'vacantes_manuales' => $abiertas->where('generada_automaticamente', false)->count(),
            'en_reclutamiento' => $vacantes->where('estado', EstadoVacante::EnReclutamiento)->count(),
            'cubiertas_este_mes' => $vacantes->where('estado', EstadoVacante::Cubierta)
                ->filter(fn (Vacante $v) => $v->updated_at !== null && $v->updated_at->isCurrentMonth())
                ->count(),
            'canceladas' => $vacantes->where('estado', EstadoVacante::Cancelada)->count(),
        ];
    }

    /**
     * Anota plantilla_autorizada/plantilla_actual/faltantes_reales en cada
     * vacante (docs/HEADCOUNT_Y_VACANTES.md): "plantilla actual" siempre se
     * calcula en vivo vía HeadcountService, nunca se importa a esta vista.
     *
     * @param  Collection<int, Vacante>  $vacantes
     */
    private function anotarPlantilla(Collection $vacantes): void
    {
        $autorizadaPorPar = HeadcountTarget::query()
            ->get(['sucursal_id', 'puesto_id', 'plantilla_autorizada'])
            ->mapWithKeys(fn (HeadcountTarget $t) => [sprintf('%d:%d', $t->sucursal_id, $t->puesto_id) => $t->plantilla_autorizada]);

        $actualPorPar = $this->headcount->plantillaActualPorSucursalPuesto();

        foreach ($vacantes as $vacante) {
            if ($vacante->sucursal_id === null || $vacante->puesto_id === null) {
                $vacante->setAttribute('plantilla_autorizada', null);
                $vacante->setAttribute('plantilla_actual', null);
                $vacante->setAttribute('faltantes_reales', null);

                continue;
            }

            $clave = sprintf('%d:%d', $vacante->sucursal_id, $vacante->puesto_id);
            $autorizada = $autorizadaPorPar[$clave] ?? null;
            $actual = (int) ($actualPorPar[$clave] ?? 0);

            $vacante->setAttribute('plantilla_autorizada', $autorizada !== null ? (int) $autorizada : null);
            $vacante->setAttribute('plantilla_actual', $actual);
            $vacante->setAttribute('faltantes_reales', $autorizada !== null ? max((int) $autorizada - $actual, 0) : null);
        }
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<int, string|int|null>>}
     */
    private function tabla(Request $request): array
    {
        $vacantes = $this->queryFiltrada($request)->orderByDesc('fecha_apertura')->get();

        $columnas = ['Puesto', 'Departamento', 'Empresa', 'Sucursal', 'Motivo', 'Estado', 'Responsable RH', 'Fecha apertura', 'Fecha estimada cobertura', 'Candidatos'];

        $filas = $vacantes->map(fn (Vacante $v) => [
            $v->puesto?->nombre,
            $v->departamento?->nombre,
            $v->empresa?->nombre,
            $v->sucursal?->nombre,
            $v->motivo->etiqueta(),
            $v->estado->etiqueta(),
            $v->responsableRh ? trim("{$v->responsableRh->name} {$v->responsableRh->apellidos}") : null,
            $v->fecha_apertura->toDateString(),
            $v->fecha_estimada_cobertura?->toDateString(),
            $v->candidatos_count,
        ])->all();

        return [$columnas, $filas];
    }

    /**
     * @return Builder<Vacante>
     */
    private function queryFiltrada(Request $request): Builder
    {
        $usuario = $request->user();

        return $this->alcance
            ->limitarPorSucursal(
                Vacante::query()->with([
                    'empresa:id,nombre',
                    'sucursal:id,nombre',
                    'departamento:id,nombre',
                    'puesto:id,nombre',
                    'gerenteSolicitante:id,name,apellidos',
                    'responsableRh:id,name,apellidos',
                ])->withCount('candidatos'),
                $usuario,
            )
            ->when($request->integer('empresa_id'), fn ($query, $valor) => $query->where('empresa_id', $valor))
            ->when($request->integer('sucursal_id'), fn ($query, $valor) => $query->where('sucursal_id', $valor))
            ->when($request->integer('departamento_id'), fn ($query, $valor) => $query->where('departamento_id', $valor))
            ->when($request->integer('puesto_id'), fn ($query, $valor) => $query->where('puesto_id', $valor))
            ->when($request->integer('responsable_rh_id'), fn ($query, $valor) => $query->where('responsable_rh_id', $valor))
            ->when($request->string('estado')->toString(), fn ($query, string $estado) => $query->where('estado', $estado))
            ->when($request->string('fecha_inicio')->toString(), fn ($query, string $valor) => $query->whereDate('fecha_apertura', '>=', $valor))
            ->when($request->string('fecha_fin')->toString(), fn ($query, string $valor) => $query->whereDate('fecha_apertura', '<=', $valor))
            ->when($request->string('busqueda')->toString(), function ($query, string $busqueda) {
                $query->where(function ($sub) use ($busqueda) {
                    $sub->whereHas('puesto', fn ($q) => $q->where('nombre', 'like', "%{$busqueda}%"))
                        ->orWhereHas('departamento', fn ($q) => $q->where('nombre', 'like', "%{$busqueda}%"));
                });
            });
    }

    public function store(StoreVacanteRequest $request): RedirectResponse
    {
        // Una vacante creada a mano por RH siempre representa una plaza
        // (a diferencia de las automáticas, que pueden agrupar varias —
        // ver VacanteAutoGenerationService); sin esto plazas_disponibles se
        // queda en 0 (default de columna) y la tarjeta se ve "sin plazas".
        Vacante::create([
            'plazas_requeridas' => 1,
            'plazas_cubiertas' => 0,
            'plazas_disponibles' => 1,
            ...$request->validated(),
            'generada_automaticamente' => false,
            'creado_por' => $request->user()?->id,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Vacante creada correctamente.']);
    }

    public function update(UpdateVacanteRequest $request, Vacante $vacante): RedirectResponse
    {
        $vacante->update($request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Vacante actualizada correctamente.']);
    }

    public function actualizarEstado(ActualizarEstadoVacanteRequest $request, Vacante $vacante): RedirectResponse
    {
        $vacante->update([
            'estado' => $request->validated('estado'),
            'motivo_cancelacion' => $request->validated('estado') === EstadoVacante::Cancelada->value
                ? $request->validated('motivo_cancelacion')
                : $vacante->motivo_cancelacion,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Estado de la vacante actualizado.']);
    }

    public function destroy(Vacante $vacante): RedirectResponse
    {
        $this->authorize('delete', $vacante);

        $vacante->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'Vacante eliminada correctamente.']);
    }

    /**
     * Cubre una vacante en uno de tres modos (ver docs/VACANTES.md):
     * - colaborador_interno: mueve al colaborador seleccionado al puesto de
     *   la vacante (promoción o cambio de puesto según niveles) y la cierra.
     * - cobertura_temporal: registra el movimiento sin tocar el puesto
     *   definitivo del colaborador; la vacante sigue abierta.
     * - candidato_externo: no muta nada aquí, solo confirma la intención —
     *   el enlace real ocurre cuando su Alta Digital se aprueba
     *   (ConversionColaboradorService ya registra el movimiento de alta).
     */
    public function cubrir(CubrirVacanteRequest $request, Vacante $vacante): RedirectResponse
    {
        $datos = $request->validated();

        if ($vacante->estado === EstadoVacante::Cubierta || $vacante->estado === EstadoVacante::Cancelada) {
            throw new RuntimeException('Esta vacante ya no admite cobertura.');
        }

        if ($datos['modo'] === 'candidato_externo') {
            return back()->with('toast', [
                'type' => 'success',
                'message' => 'Registra o continúa el Alta Digital del candidato enlazándola a esta vacante; la vacante se cerrará automáticamente al aprobarse.',
            ]);
        }

        $usuario = User::query()->findOrFail((int) $datos['user_id']);

        if ($datos['modo'] === 'cobertura_temporal') {
            $puesto = $vacante->puesto_id ? Puesto::query()->find($vacante->puesto_id) : null;
            abort_unless($puesto !== null, 422, 'La vacante no tiene un puesto asociado.');

            $this->movimientos->registrarCoberturaTemporal(
                $usuario,
                $puesto,
                $request->user(),
                Carbon::parse($datos['fecha_inicio']),
                isset($datos['fecha_fin']) ? Carbon::parse($datos['fecha_fin']) : null,
                $datos['motivo'] ?? null,
                $vacante->id,
            );

            return back()->with('toast', ['type' => 'success', 'message' => 'Cobertura temporal registrada. La vacante permanece abierta.']);
        }

        // colaborador_interno: mueve al colaborador al puesto de la vacante.
        $antes = $this->movimientos->snapshot($usuario);

        $usuario->update([
            'puesto_id' => $vacante->puesto_id,
            'departamento_id' => $vacante->departamento_id ?? $usuario->departamento_id,
            'sucursal_principal_id' => $vacante->sucursal_id ?? $usuario->sucursal_principal_id,
        ]);

        $this->movimientos->registrarCambioPuesto(
            $usuario->fresh(),
            $antes,
            $request->user(),
            $datos['motivo'] ?? null,
            $vacante->id,
        );

        $vacante->update(['estado' => EstadoVacante::Cubierta->value]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Vacante cubierta con colaborador interno.']);
    }
}
