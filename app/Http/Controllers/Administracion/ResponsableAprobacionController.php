<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administracion\GuardarResponsableAprobacionRequest;
use App\Models\ResponsableAprobacion;
use App\Services\Organigrama\ResponsableAprobacionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Organización → Responsables de aprobación: quién da el visto bueno de las
 * solicitudes de cada persona o puesto cuando NO debe ser su jefe
 * organizacional. Ver: `organigrama.ver`; asignar/quitar:
 * `organigrama.editar` (RH / super admin). Toda la regla vive en
 * ResponsableAprobacionService + AprobacionOrganizacionalService.
 */
class ResponsableAprobacionController extends Controller
{
    public function __construct(private readonly ResponsableAprobacionService $responsables) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('organigrama.ver') || $request->user()->can('organigrama.editar'), 403);

        return Inertia::render('Administracion/ResponsablesAprobacion/Index', [
            ...$this->responsables->pantalla($request->user()),
            'puedeEditar' => $request->user()->can('organigrama.editar'),
        ]);
    }

    public function store(GuardarResponsableAprobacionRequest $request): RedirectResponse
    {
        /** @var array{colaborador_id?: int|null, puesto_id?: int|null, responsable_colaborador_id: int, motivo: string} $datos */
        $datos = $request->validated();

        $this->responsables->asignar($datos, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Responsable de aprobación guardado. Sus solicitudes abiertas ya van con esa persona.']);
    }

    public function destroy(Request $request, ResponsableAprobacion $responsable): RedirectResponse
    {
        abort_unless($request->user()->can('organigrama.editar'), 403);

        $datos = $request->validate(['motivo' => ['nullable', 'string', 'max:500']]);

        $this->responsables->quitar($responsable, $request->user(), $datos['motivo'] ?? null);

        return back()->with('toast', ['type' => 'success', 'message' => 'Se quitó el responsable: vuelve a aplicar la regla del organigrama.']);
    }
}
