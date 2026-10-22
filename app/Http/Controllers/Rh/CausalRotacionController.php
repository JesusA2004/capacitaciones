<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\CierreLaboral\GuardarCausalRotacionRequest;
use App\Models\CausalRotacion;
use App\Services\CierreLaboral\CausalRotacionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Causales de rotación (Bajas y finiquitos → «Causales de rotación»).
 * Delgado: autoriza con CausalRotacionPolicy y delega a
 * CausalRotacionService (alta, edición, activar/desactivar, orden y
 * eliminación solo de las nunca usadas).
 */
class CausalRotacionController extends Controller
{
    public function __construct(private readonly CausalRotacionService $causales) {}

    public function store(GuardarCausalRotacionRequest $request): RedirectResponse
    {
        $this->authorize('create', CausalRotacion::class);
        $causal = $this->causales->crear($request->datos(), $request->user());

        return $this->ok(sprintf('Causal «%s» agregada.', $causal->nombre));
    }

    public function update(GuardarCausalRotacionRequest $request, CausalRotacion $causal): RedirectResponse
    {
        $this->authorize('update', $causal);
        $this->causales->actualizar($causal, $request->datos(), $request->user());

        return $this->ok('Causal actualizada.');
    }

    public function activo(Request $request, CausalRotacion $causal): RedirectResponse
    {
        $this->authorize('activar', $causal);
        $datos = $request->validate(['activo' => ['required', 'boolean']]);
        $this->causales->cambiarActivo($causal, (bool) $datos['activo'], $request->user());

        return $this->ok($datos['activo'] ? 'Causal activada: ya se ofrece al registrar una baja.' : 'Causal desactivada: ya no se ofrece; el historial la conserva (las causales nunca se borran).');
    }

    public function mover(Request $request, CausalRotacion $causal): RedirectResponse
    {
        $this->authorize('ordenar', $causal);
        $datos = $request->validate(['direccion' => ['required', Rule::in(['arriba', 'abajo'])]]);
        $this->causales->mover($causal, (string) $datos['direccion'], $request->user());

        return back();
    }

    private function ok(string $mensaje): RedirectResponse
    {
        return back()->with('toast', ['type' => 'success', 'message' => $mensaje]);
    }
}
