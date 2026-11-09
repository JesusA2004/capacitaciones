<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administracion\UbicarPuestoRequest;
use App\Models\Puesto;
use App\Services\Organigrama\UbicacionPuestoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * «Ubicar / mover puesto» desde el organigrama (árbol General, Por puestos y
 * la bandeja de puestos sin ubicar). Delgado: la regla vive en
 * UbicacionPuestoService; la autorización en PuestoPolicy::editarJerarquia.
 */
class UbicacionPuestoController extends Controller
{
    public function __construct(private readonly UbicacionPuestoService $ubicacion) {}

    public function opciones(Puesto $puesto): JsonResponse
    {
        $this->authorize('editarJerarquia', $puesto);

        return response()->json($this->ubicacion->opciones($puesto));
    }

    public function vistaPrevia(UbicarPuestoRequest $request, Puesto $puesto): JsonResponse
    {
        return response()->json($this->ubicacion->vistaPrevia($puesto, $request->superiorId(), $request->departamentoId()));
    }

    public function ubicar(UbicarPuestoRequest $request, Puesto $puesto): RedirectResponse
    {
        $actualizado = $this->ubicacion->ubicar($puesto, $request->superiorId(), $request->departamentoId(), $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('«%s» quedó ubicado debajo de «%s».', $actualizado->nombre, (string) $actualizado->puestoSuperior?->nombre),
        ]);
    }
}
