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
        /** @var array{colaborador_id: int, puesto_id: int, sucursal_id?: int|null, region_id?: int|null, motivo?: string|null, nota?: string|null, fecha_inicio?: string|null, ocupa_plaza?: bool|null} $datos */
        $datos = $request->validated();
        $datos['ocupa_plaza'] = $request->filled('ocupa_plaza') ? $request->boolean('ocupa_plaza') : null;

        $cobertura = $this->coberturas->asignar($datos, $request->user());

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
