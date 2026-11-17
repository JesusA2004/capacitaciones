<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administracion\StoreCoberturaPuestoRequest;
use App\Models\CoberturaPuesto;
use App\Services\Organigrama\CoberturaPuestoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Coberturas temporales desde el organigrama (asignar / terminar). Toda la
 * regla vive en CoberturaPuestoService.
 */
class CoberturaPuestoController extends Controller
{
    public function __construct(private readonly CoberturaPuestoService $coberturas) {}

    public function store(StoreCoberturaPuestoRequest $request): RedirectResponse
    {
        $request->validated();

        // Tipos reales desde la petición ya validada (el Service compara
        // ids con ===: un "5" de formulario nunca debe llegar como string).
        $cobertura = $this->coberturas->asignar([
            'colaborador_id' => $request->integer('colaborador_id'),
            'puesto_id' => $request->integer('puesto_id'),
            'sucursal_id' => $request->filled('sucursal_id') ? $request->integer('sucursal_id') : null,
            'region_id' => $request->filled('region_id') ? $request->integer('region_id') : null,
            'motivo' => $request->filled('motivo') ? $request->string('motivo')->toString() : null,
            'nota' => $request->filled('nota') ? $request->string('nota')->toString() : null,
            'fecha_inicio' => $request->filled('fecha_inicio') ? $request->string('fecha_inicio')->toString() : null,
            'ocupa_plaza' => $request->filled('ocupa_plaza') ? $request->boolean('ocupa_plaza') : null,
        ], $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => $cobertura->ocupa_plaza
            ? 'Listo: ocupa ambos puestos. La plaza cuenta como ocupada y no queda vacante.'
            : 'Listo: cubre mientras se contrata. La plaza sigue como vacante en headcount.']);
    }

    public function finalizar(Request $request, CoberturaPuesto $cobertura): RedirectResponse
    {
        $usuario = $request->user();
        $this->authorize('finalizar', $cobertura);

        $this->coberturas->finalizar($cobertura, $usuario);

        return back()->with('toast', ['type' => 'success', 'message' => 'Se quitó la cobertura temporal: la plaza vuelve a verse vacante.']);
    }
}
