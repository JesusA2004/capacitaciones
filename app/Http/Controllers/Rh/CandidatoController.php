<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EstadoCandidato;
use App\Enums\TipoSeguimientoCandidato;
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
use App\Models\Vacante;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Reclutamiento\CvStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CandidatoController extends Controller
{
    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly CvStorageService $cvStorage,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Candidato::class);

        $usuario = $request->user();

        $candidatos = $this->alcance
            ->limitarPorSucursal(
                Candidato::query()->with([
                    'empresa:id,nombre',
                    'sucursal:id,nombre',
                    'puestoObjetivo:id,nombre',
                    'vacante:id,puesto_id',
                    'responsableRh:id,name,apellidos',
                    'gerenteInvolucrado:id,name,apellidos',
                ]),
                $usuario,
            )
            ->when($request->integer('empresa_id'), fn ($query, $valor) => $query->where('empresa_id', $valor))
            ->when($request->integer('sucursal_id'), fn ($query, $valor) => $query->where('sucursal_id', $valor))
            ->when($request->integer('puesto_objetivo_id'), fn ($query, $valor) => $query->where('puesto_objetivo_id', $valor))
            ->when($request->integer('vacante_id'), fn ($query, $valor) => $query->where('vacante_id', $valor))
            ->when($request->string('busqueda')->toString(), function ($query, string $busqueda) {
                $query->where(function ($sub) use ($busqueda): void {
                    $sub->where('nombre', 'like', "%{$busqueda}%")
                        ->orWhere('apellidos', 'like', "%{$busqueda}%")
                        ->orWhere('correo', 'like', "%{$busqueda}%");
                });
            })
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Rh/Candidatos/Index', [
            'candidatos' => $candidatos,
            'filtros' => $request->only('empresa_id', 'sucursal_id', 'puesto_objetivo_id', 'vacante_id', 'busqueda'),
            'opciones' => $this->opciones(),
        ]);
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
            'estados' => array_map(fn (EstadoCandidato $e) => ['value' => $e->value, 'etiqueta' => $e->etiqueta()], EstadoCandidato::cases()),
            'tiposSeguimiento' => array_map(fn (TipoSeguimientoCandidato $t) => ['value' => $t->value, 'etiqueta' => $t->etiqueta()], TipoSeguimientoCandidato::cases()),
        ];
    }
}
