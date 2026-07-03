<?php

namespace App\Http\Controllers\Asignaciones;

use App\Http\Controllers\Controller;
use App\Http\Requests\Asignaciones\PrevisualizarAsignacionRequest;
use App\Http\Requests\Asignaciones\StoreAsignacionRequest;
use App\Models\Asignacion;
use App\Models\AsignacionDestino;
use App\Models\Curso;
use App\Models\Departamento;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Asignaciones\AsignacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class AsignacionController extends Controller
{
    public function __construct(private readonly AsignacionService $asignacionService) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Asignacion::class);

        $asignaciones = Asignacion::query()
            ->with(['asignable', 'responsable:id,name,apellidos'])
            ->withCount('asignacionesUsuario')
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Asignaciones/Index', [
            'asignaciones' => $asignaciones,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Asignacion::class);

        return Inertia::render('Asignaciones/Create', [
            'cursosDisponibles' => Curso::query()->orderBy('titulo')->get(['id', 'titulo']),
            'sucursalesDisponibles' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre']),
            'departamentosDisponibles' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
            'puestosDisponibles' => Puesto::query()->orderBy('nombre')->get(['id', 'nombre']),
            'rolesDisponibles' => Role::query()->orderBy('name')->get(['id', 'name']),
            'usuariosDisponibles' => User::query()->orderBy('name')->get(['id', 'name', 'apellidos']),
        ]);
    }

    public function store(StoreAsignacionRequest $request): RedirectResponse
    {
        $curso = Curso::findOrFail($request->integer('curso_id'));

        $this->asignacionService->crear($curso, [
            'nombre' => $request->string('nombre')->value(),
            'responsable_id' => $request->user()->id,
            'fecha_inicio' => $request->input('fecha_inicio'),
            'fecha_limite' => $request->input('fecha_limite'),
            'obligatoria' => $request->boolean('obligatoria', true),
        ], $request->input('destinos'));

        return redirect()->route('asignaciones.index')
            ->with('toast', ['type' => 'success', 'message' => 'Asignación creada. Se está aplicando a los colaboradores correspondientes.']);
    }

    public function previsualizar(PrevisualizarAsignacionRequest $request): JsonResponse
    {
        /** @var array<int, array{tipo: string, id: int|null}> $destinosInput */
        $destinosInput = $request->input('destinos');

        $destinos = collect($destinosInput)
            ->map(fn (array $destino) => new AsignacionDestino([
                'tipo_destino' => $destino['tipo'],
                'destino_id' => $destino['id'] ?? null,
            ]));

        $totalPorDestino = $destinos->sum(fn (AsignacionDestino $destino) => $this->asignacionService->resolverUsuariosDeDestino($destino)->count());

        $usuarios = $this->asignacionService->resolverUsuarios($destinos);

        return response()->json([
            'total' => $usuarios->count(),
            'posibles_duplicados' => max(0, $totalPorDestino - $usuarios->count()),
            'muestra' => $usuarios->take(25)->map(fn (User $usuario) => [
                'id' => $usuario->id,
                'nombre' => trim("{$usuario->name} {$usuario->apellidos}"),
                'email' => $usuario->email,
            ])->values(),
        ]);
    }

    public function cancelar(Asignacion $asignacion): RedirectResponse
    {
        $this->authorize('cancelar', $asignacion);

        $this->asignacionService->cancelar($asignacion);

        return back()->with('toast', ['type' => 'success', 'message' => 'Asignación cancelada correctamente.']);
    }
}
