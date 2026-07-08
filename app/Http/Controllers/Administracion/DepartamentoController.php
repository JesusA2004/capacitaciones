<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administracion\StoreDepartamentoRequest;
use App\Http\Requests\Administracion\UpdateDepartamentoRequest;
use App\Models\Departamento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DepartamentoController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Departamento::class);

        $departamentos = Departamento::query()
            ->withCount(['puestos', 'usuarios'])
            ->when($request->string('busqueda')->toString(), fn ($query, string $busqueda) => $query->where('nombre', 'like', "%{$busqueda}%"))
            ->orderBy('nombre')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Administracion/Departamentos/Index', [
            'departamentos' => $departamentos,
            'filtros' => $request->only('busqueda'),
            'estadisticas' => [
                'total' => Departamento::count(),
                'activos' => Departamento::where('activo', true)->count(),
                'inactivos' => Departamento::where('activo', false)->count(),
            ],
        ]);
    }

    public function store(StoreDepartamentoRequest $request): RedirectResponse
    {
        Departamento::create($request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Departamento creado correctamente.']);
    }

    public function update(UpdateDepartamentoRequest $request, Departamento $departamento): RedirectResponse
    {
        $departamento->update($request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Departamento actualizado correctamente.']);
    }

    public function destroy(Departamento $departamento): RedirectResponse
    {
        $this->authorize('delete', $departamento);

        $departamento->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'Departamento eliminado correctamente.']);
    }
}
