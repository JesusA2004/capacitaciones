<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EstadoCandidato;
use App\Enums\TipoSeguimientoCandidato;
use App\Exports\ReporteRhExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\ActualizarEstadoCandidatoRequest;
use App\Http\Requests\Rh\StoreCandidatoRequest;
use App\Http\Requests\Rh\StoreSeguimientoCandidatoRequest;
use App\Http\Requests\Rh\SubirCvCandidatoRequest;
use App\Http\Requests\Rh\UpdateCandidatoRequest;
use App\Models\Candidato;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vacante;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Reclutamiento\CvStorageService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CandidatoController extends Controller
{
    private const FILTROS = ['empresa_id', 'sucursal_id', 'departamento_id', 'puesto_objetivo_id', 'vacante_id', 'responsable_rh_id', 'busqueda', 'fecha_inicio', 'fecha_fin'];

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly CvStorageService $cvStorage,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Candidato::class);

        $candidatos = $this->queryFiltrada($request)->orderByDesc('created_at')->get();

        return Inertia::render('Rh/Candidatos/Index', [
            'candidatos' => $candidatos,
            'filtros' => $request->only(self::FILTROS),
            'opciones' => $this->opciones(),
        ]);
    }

    public function exportarExcel(Request $request): HttpResponse
    {
        $this->authorize('viewAny', Candidato::class);

        [$columnas, $filas] = $this->tabla($request);

        return Excel::download(
            new ReporteRhExport('Candidatos', $columnas, $filas),
            'candidatos-'.now()->format('Y-m-d').'.xlsx',
        );
    }

    public function exportarPdf(Request $request): HttpResponse
    {
        $this->authorize('viewAny', Candidato::class);

        [$columnas, $filas] = $this->tabla($request);

        return Pdf::loadView('pdf.reporte-rh', ['titulo' => 'Candidatos', 'columnas' => $columnas, 'filas' => $filas])
            ->setPaper('letter', 'landscape')
            ->download('candidatos-'.now()->format('Y-m-d').'.pdf');
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<int, string|int|null>>}
     */
    private function tabla(Request $request): array
    {
        $candidatos = $this->queryFiltrada($request)->orderByDesc('created_at')->get();

        $columnas = ['Nombre', 'Correo', 'Teléfono', 'Puesto objetivo', 'Empresa', 'Sucursal', 'Responsable RH', 'Estado', 'Fecha de registro'];

        $filas = $candidatos->map(fn (Candidato $c) => [
            trim("{$c->nombre} {$c->apellidos}"),
            $c->correo,
            $c->telefono,
            $c->puestoObjetivo?->nombre,
            $c->empresa?->nombre,
            $c->sucursal?->nombre,
            $c->responsableRh ? trim("{$c->responsableRh->name} {$c->responsableRh->apellidos}") : null,
            $c->estado->etiqueta(),
            $c->created_at->toDateString(),
        ])->all();

        return [$columnas, $filas];
    }

    /**
     * @return Builder<Candidato>
     */
    private function queryFiltrada(Request $request): Builder
    {
        $usuario = $request->user();

        return $this->alcance
            ->limitarPorSucursal(
                Candidato::query()->with([
                    'empresa:id,nombre',
                    'sucursal:id,nombre',
                    'departamento:id,nombre',
                    'puestoObjetivo:id,nombre',
                    'vacante:id,puesto_id',
                    'responsableRh:id,name,apellidos',
                    'gerenteInvolucrado:id,name,apellidos',
                ]),
                $usuario,
            )
            ->when($request->integer('empresa_id'), fn ($query, $valor) => $query->where('empresa_id', $valor))
            ->when($request->integer('sucursal_id'), fn ($query, $valor) => $query->where('sucursal_id', $valor))
            ->when($request->integer('departamento_id'), fn ($query, $valor) => $query->where('departamento_id', $valor))
            ->when($request->integer('puesto_objetivo_id'), fn ($query, $valor) => $query->where('puesto_objetivo_id', $valor))
            ->when($request->integer('vacante_id'), fn ($query, $valor) => $query->where('vacante_id', $valor))
            ->when($request->integer('responsable_rh_id'), fn ($query, $valor) => $query->where('responsable_rh_id', $valor))
            ->when($request->string('fecha_inicio')->toString(), fn ($query, string $valor) => $query->whereDate('created_at', '>=', $valor))
            ->when($request->string('fecha_fin')->toString(), fn ($query, string $valor) => $query->whereDate('created_at', '<=', $valor))
            ->when($request->string('busqueda')->toString(), function ($query, string $busqueda) {
                $query->where(function ($sub) use ($busqueda): void {
                    $sub->where('nombre', 'like', "%{$busqueda}%")
                        ->orWhere('apellidos', 'like', "%{$busqueda}%")
                        ->orWhere('correo', 'like', "%{$busqueda}%");
                });
            });
    }

    public function show(Candidato $candidato): Response
    {
        $this->authorize('view', $candidato);

        $candidato->load([
            'empresa:id,nombre',
            'sucursal:id,nombre',
            'departamento:id,nombre',
            'puestoObjetivo:id,nombre',
            'vacante:id,puesto_id,estado',
            'responsableRh:id,name,apellidos',
            'gerenteInvolucrado:id,name,apellidos',
            'seguimientos.registradoPor:id,name,apellidos',
        ]);

        return Inertia::render('Rh/Candidatos/Show', [
            'candidato' => $candidato,
            'opciones' => $this->opciones(),
        ]);
    }

    public function store(StoreCandidatoRequest $request): RedirectResponse
    {
        $candidato = Candidato::create([
            ...$request->validated(),
            'creado_por' => $request->user()?->id,
        ]);

        $candidato->seguimientos()->create([
            'tipo' => TipoSeguimientoCandidato::Nota,
            'nota' => 'Candidato registrado.',
            'estado_nuevo' => EstadoCandidato::Nuevo->value,
            'fecha' => now(),
            'registrado_por' => $request->user()?->id,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Candidato registrado correctamente.']);
    }

    public function update(UpdateCandidatoRequest $request, Candidato $candidato): RedirectResponse
    {
        $candidato->update($request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Candidato actualizado correctamente.']);
    }

    public function subirCv(SubirCvCandidatoRequest $request, Candidato $candidato): RedirectResponse
    {
        if ($candidato->cv_path) {
            $this->cvStorage->eliminar($candidato->cv_path);
        }

        $archivo = $request->file('cv');
        $nombreInterno = $this->cvStorage->nombreInterno($archivo->getClientOriginalName());
        $ruta = $this->cvStorage->rutaCv($candidato->id, $nombreInterno);
        $this->cvStorage->guardar($archivo, $ruta);

        $candidato->update([
            'cv_disk' => config('reclutamiento.disk'),
            'cv_path' => $ruta,
            'cv_original_name' => $archivo->getClientOriginalName(),
            'cv_mime' => $archivo->getClientMimeType(),
            'cv_size' => $archivo->getSize(),
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'CV cargado correctamente.']);
    }

    public function descargarCv(Candidato $candidato): StreamedResponse
    {
        $this->authorize('view', $candidato);

        abort_unless($candidato->cv_path !== null, 404);

        return $this->cvStorage->respuesta($candidato->cv_path, [
            'Content-Disposition' => 'attachment; filename="'.$candidato->cv_original_name.'"',
        ]);
    }

    public function actualizarEstado(ActualizarEstadoCandidatoRequest $request, Candidato $candidato): RedirectResponse
    {
        $estadoAnterior = $candidato->estado;
        $nuevoEstado = EstadoCandidato::from($request->validated('estado'));

        $candidato->update(['estado' => $nuevoEstado]);

        $candidato->seguimientos()->create([
            'tipo' => TipoSeguimientoCandidato::CambioEstado,
            'nota' => $request->validated('nota') ?? "Estado actualizado a «{$nuevoEstado->etiqueta()}».",
            'estado_anterior' => $estadoAnterior->value,
            'estado_nuevo' => $nuevoEstado->value,
            'fecha' => now(),
            'registrado_por' => $request->user()?->id,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Estado del candidato actualizado.']);
    }

    public function agregarSeguimiento(StoreSeguimientoCandidatoRequest $request, Candidato $candidato): RedirectResponse
    {
        $candidato->seguimientos()->create([
            ...$request->validated(),
            'fecha' => $request->validated('fecha') ?? now(),
            'registrado_por' => $request->user()?->id,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Seguimiento agregado.']);
    }

    public function destroy(Candidato $candidato): RedirectResponse
    {
        $this->authorize('delete', $candidato);

        if ($candidato->cv_path) {
            $this->cvStorage->eliminar($candidato->cv_path);
        }

        $candidato->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'Candidato eliminado correctamente.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function opciones(): array
    {
        return [
            'empresas' => Empresa::query()->orderBy('nombre')->get(['id', 'nombre']),
            'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre', 'empresa_id']),
            'departamentos' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
            'puestos' => Puesto::query()->orderBy('nombre')->get(['id', 'nombre', 'departamento_id']),
            'vacantes' => Vacante::query()->whereNotIn('estado', ['cubierta', 'cancelada'])->orderByDesc('fecha_apertura')->get(['id', 'puesto_id']),
            'responsables' => User::query()->role(['rh_admin', 'rh_auxiliar'])->orderBy('name')->get(['id', 'name', 'apellidos']),
            'estados' => array_map(fn (EstadoCandidato $e) => ['value' => $e->value, 'etiqueta' => $e->etiqueta()], EstadoCandidato::cases()),
            'tiposSeguimiento' => array_map(fn (TipoSeguimientoCandidato $t) => ['value' => $t->value, 'etiqueta' => $t->etiqueta()], TipoSeguimientoCandidato::cases()),
        ];
    }
}
