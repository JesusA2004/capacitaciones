<?php

namespace App\Http\Controllers\Administracion;

use App\Enums\EstadoUsuario;
use App\Enums\TipoAsignacionNodoComercial;
use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\NodoComercial;
use App\Models\Puesto;
use App\Services\MatrizComercial\MatrizComercialService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Matriz comercial / territorial (MATRIZ -> Región -> Zona -> Ruta) — vista
 * B del Organigrama (ver docs/HEADCOUNT_Y_VACANTES.md). Reutiliza el mismo
 * gate que la jerarquía de puestos (`organigrama.ver`/`organigrama.editar`,
 * ver App\Policies\PuestoPolicy): son dos árboles distintos, un solo
 * criterio de acceso.
 */
class MatrizComercialController extends Controller
{
    public function __construct(private readonly MatrizComercialService $matriz) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Puesto::class);

        return Inertia::render('Administracion/MatrizComercial/Index', [
            'arbol' => $this->matriz->arbol(),
            'resumen' => $this->matriz->resumen(),
            'gestoresDisponibles' => Colaborador::query()
                ->where('estatus', EstadoUsuario::Activo)
                ->orderBy('name')
                ->get(['id', 'name', 'apellidos']),
        ]);
    }

    public function asignarResponsable(Request $request, NodoComercial $nodo): RedirectResponse
    {
        abort_unless($request->user()?->can('puestos.administrar') || $request->user()?->can('organigrama.editar'), 403);

        $datos = $request->validate([
            'responsable_colaborador_id' => ['nullable', 'integer', 'exists:colaboradores,id'],
        ]);

        $this->matriz->asignarResponsable(
            $nodo,
            $datos['responsable_colaborador_id'] !== null
                ? Colaborador::query()->where('id', $datos['responsable_colaborador_id'])->first()
                : null,
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Gestor actualizado.']);
    }

    public function agregarApoyo(Request $request, NodoComercial $nodo): RedirectResponse
    {
        abort_unless($request->user()?->can('puestos.administrar') || $request->user()?->can('organigrama.editar'), 403);

        $datos = $request->validate([
            'colaborador_id' => ['required', 'integer', 'exists:colaboradores,id'],
            'tipo' => ['required', 'in:apoyo,volante'],
        ]);

        $colaborador = Colaborador::query()->where('id', $datos['colaborador_id'])->firstOrFail();
        $this->matriz->agregarApoyo($nodo, $colaborador, TipoAsignacionNodoComercial::from($datos['tipo']));

        return back()->with('toast', ['type' => 'success', 'message' => 'Colaborador agregado a la ruta.']);
    }

    public function quitarApoyo(Request $request, NodoComercial $nodo): RedirectResponse
    {
        abort_unless($request->user()?->can('puestos.administrar') || $request->user()?->can('organigrama.editar'), 403);

        $datos = $request->validate([
            'colaborador_id' => ['required', 'integer', 'exists:colaboradores,id'],
            'tipo' => ['required', 'in:apoyo,volante'],
        ]);

        $colaborador = Colaborador::query()->where('id', $datos['colaborador_id'])->firstOrFail();
        $this->matriz->quitarAsignacion($nodo, $colaborador, TipoAsignacionNodoComercial::from($datos['tipo']));

        return back()->with('toast', ['type' => 'success', 'message' => 'Colaborador quitado de la ruta.']);
    }
}
