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
 * Descriptivos y perfiles de puesto: el «deber ser» del puesto y lo que se
 * busca en el candidato. Ver lo puede RH y las gerencias; editar, solo RH.
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

    /** Edición en su propia página (la lectura nunca mezcla formularios). */
    public function edit(Request $request, Puesto $puesto): Response
    {
        abort_unless($request->user()?->can('perfiles_puesto.editar'), 403);

        return Inertia::render('Rh/PerfilesPuesto/Editar', $this->perfiles->detalle($puesto));
    }

    public function update(GuardarPerfilPuestoRequest $request, Puesto $puesto): RedirectResponse
    {
        $this->perfiles->guardar($puesto, $request->validated(), $request->user());

        return to_route('rh.perfiles-puesto.show', $puesto)->with('toast', ['type' => 'success', 'message' => 'Descriptivo guardado.']);
    }
}
