<?php

namespace App\Http\Controllers\Rh;

use App\Enums\FormaSalida;
use App\Http\Controllers\Controller;
use App\Http\Requests\CierreLaboral\GuardarCausalRotacionRequest;
use App\Models\CausalGrupo;
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

    public function grupoStore(Request $request): RedirectResponse
    {
        $this->authorize('create', CausalRotacion::class);
        $datos = $request->validate([
            'forma_salida' => ['required', Rule::enum(FormaSalida::class)],
            'nombre' => ['required', 'string', 'min:3', 'max:80'],
            'descripcion' => ['nullable', 'string', 'max:300'],
        ], ['nombre.required' => 'Escribe el nombre del subcatálogo.', 'nombre.min' => 'El nombre debe tener al menos 3 letras.']);
        $grupo = $this->causales->crearGrupo($datos, $request->user());

        return $this->ok(sprintf('Subcatálogo «%s» agregado.', $grupo->nombre));
    }

    public function grupoUpdate(Request $request, CausalGrupo $grupo): RedirectResponse
    {
        $this->authorize('editarGrupos', CausalRotacion::class);
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'min:3', 'max:80'],
            'descripcion' => ['nullable', 'string', 'max:300'],
        ], ['nombre.required' => 'Escribe el nombre del subcatálogo.', 'nombre.min' => 'El nombre debe tener al menos 3 letras.']);
        $this->causales->actualizarGrupo($grupo, $datos, $request->user());

        return $this->ok('Subcatálogo actualizado.');
    }

    public function grupoActivo(Request $request, CausalGrupo $grupo): RedirectResponse
    {
        $this->authorize('activarGrupos', CausalRotacion::class);
        $datos = $request->validate(['activo' => ['required', 'boolean']]);
        $this->causales->cambiarActivoGrupo($grupo, (bool) $datos['activo'], $request->user());

        return $this->ok($datos['activo'] ? 'Subcatálogo activado: sus causales activas vuelven a ofrecerse.' : 'Subcatálogo desactivado: sus causales ya no se ofrecen; el historial las conserva.');
    }

    private function ok(string $mensaje): RedirectResponse
    {
        return back()->with('toast', ['type' => 'success', 'message' => $mensaje]);
    }
}
