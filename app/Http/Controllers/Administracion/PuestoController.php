<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administracion\StorePuestoRequest;
use App\Http\Requests\Administracion\UpdatePuestoRequest;
use App\Models\Departamento;
use App\Models\Puesto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PuestoController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Puesto::class);

        $puestos = Puesto::query()
            ->withCount('usuarios')
            ->with('departamento:id,nombre')
            ->when($request->string('busqueda')->toString(), fn ($query, string $busqueda) => $query->where('nombre', 'like', "%{$busqueda}%"))
            ->orderBy('nombre')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Administracion/Puestos/Index', [
            'puestos' => $puestos,
            'filtros' => $request->only('busqueda'),
            'departamentosDisponibles' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
            'estadisticas' => [
                'total' => Puesto::count(),
                'activos' => Puesto::where('activo', true)->count(),
                'inactivos' => Puesto::where('activo', false)->count(),
            ],
        ]);
    }

    public function store(StorePuestoRequest $request): RedirectResponse
    {
        Puesto::create($request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Puesto creado correctamente.']);
    }

    public function update(UpdatePuestoRequest $request, Puesto $puesto): RedirectResponse
    {
        $puesto->update($request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Puesto actualizado correctamente.']);
    }

    public function destroy(Puesto $puesto): RedirectResponse
    {
        $this->authorize('delete', $puesto);

        $puesto->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'Puesto eliminado correctamente.']);
    }
}
