<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\Puestos\GuardarPerfilPuestoRequest;
use App\Models\Puesto;
use App\Services\Puestos\PerfilPuestoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Perfiles de puesto: lo que se busca en el candidato y el «deber ser» del
 * puesto. Ver lo puede RH y las gerencias; editar, solo RH.
 */
class PerfilPuestoController extends Controller
{
    public function __construct(private readonly PerfilPuestoService $perfiles) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('perfiles_puesto.ver'), 403);

        return Inertia::render('Rh/PerfilesPuesto/Index', [
            'puestos' => $this->perfiles->listado(),
            'puedeEditar' => $request->user()->can('perfiles_puesto.editar'),
        ]);
    }

    public function show(Request $request, Puesto $puesto): Response
    {
        abort_unless($request->user()?->can('perfiles_puesto.ver'), 403);

        return Inertia::render('Rh/PerfilesPuesto/Show', [
            ...$this->perfiles->detalle($puesto),
            'puedeEditar' => $request->user()->can('perfiles_puesto.editar'),
        ]);
    }

    public function update(GuardarPerfilPuestoRequest $request, Puesto $puesto): RedirectResponse
    {
        $this->perfiles->guardar($puesto, $request->validated(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Perfil del puesto guardado.']);
    }
}
