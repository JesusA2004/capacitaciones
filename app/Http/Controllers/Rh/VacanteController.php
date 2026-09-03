<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EstadoVacante;
use App\Enums\MotivoVacante;
use App\Exports\ReporteRhExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\ActualizarEstadoVacanteRequest;
use App\Http\Requests\Rh\StoreVacanteRequest;
use App\Http\Requests\Rh\UpdateVacanteRequest;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vacante;
use App\Services\AlcanceOrganizacionalService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class VacanteController extends Controller
{
    private const FILTROS = ['empresa_id', 'sucursal_id', 'departamento_id', 'puesto_id', 'responsable_rh_id', 'estado', 'busqueda', 'fecha_inicio', 'fecha_fin'];

    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Vacante::class);

        $vacantes = $this->queryFiltrada($request)->orderByDesc('fecha_apertura')->get();

        return Inertia::render('Rh/Vacantes/Index', [
            'vacantes' => $vacantes,
            'filtros' => $request->only(self::FILTROS),
            'opciones' => [
                'empresas' => Empresa::query()->orderBy('nombre')->get(['id', 'nombre']),
                'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre', 'empresa_id']),
                'departamentos' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
                'puestos' => Puesto::query()->orderBy('nombre')->get(['id', 'nombre', 'departamento_id']),
                'responsables' => User::query()->role(['rh_admin', 'rh_auxiliar'])->orderBy('name')->get(['id', 'name', 'apellidos']),
                'motivos' => array_map(fn (MotivoVacante $m) => ['value' => $m->value, 'etiqueta' => $m->etiqueta()], MotivoVacante::cases()),
                'estados' => array_map(fn (EstadoVacante $e) => ['value' => $e->value, 'etiqueta' => $e->etiqueta()], EstadoVacante::cases()),
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
        Vacante::create([
            ...$request->validated(),
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
        $vacante->update(['estado' => $request->validated('estado')]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Estado de la vacante actualizado.']);
    }

    public function destroy(Vacante $vacante): RedirectResponse
    {
        $this->authorize('delete', $vacante);

        $vacante->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'Vacante eliminada correctamente.']);
    }
}
