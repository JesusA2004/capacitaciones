<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administracion\MoverEnOrganigramaRequest;
use App\Models\Colaborador;
use App\Services\Organigrama\MovimientoOrganigramaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Acciones por nodo del organigrama (panel lateral): subir / bajar / mover
 * bajo… Delgado: toda la regla vive en MovimientoOrganigramaService.
 */
class OrganigramaAccionesController extends Controller
{
    public function __construct(
        private readonly MovimientoOrganigramaService $movimientos,
    ) {}

    public function opciones(Request $request, Colaborador $colaborador): JsonResponse
    {
        $this->authorize('moverEnOrganigrama', $colaborador);

        return response()->json($this->movimientos->opciones($colaborador));
    }

    public function vistaPrevia(MoverEnOrganigramaRequest $request, Colaborador $colaborador): JsonResponse
    {
        return response()->json($this->movimientos->vistaPrevia($colaborador, $request->destino()));
    }

    public function mover(MoverEnOrganigramaRequest $request, Colaborador $colaborador): RedirectResponse
    {
        $this->movimientos->aplicar($colaborador, $request->destino(), $request->string('accion')->toString(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => sprintf('Listo: %s ya está en su nueva plaza y su jefe directo se recalculó.', $colaborador->nombreCompleto())]);
    }
}
