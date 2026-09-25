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
        /** @var array{colaborador_id: int, puesto_id: int, sucursal_id?: int|null, region_id?: int|null, motivo: string, nota?: string|null, fecha_inicio?: string|null} $datos */
        $datos = $request->validated();

        $this->coberturas->asignar($datos, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Cobertura registrada: ya aparece en el organigrama.']);
    }

    public function finalizar(Request $request, CoberturaPuesto $cobertura): RedirectResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('organigrama.editar') || $usuario->can('puestos.administrar'), 403);

        $this->coberturas->finalizar($cobertura, $usuario);

        return back()->with('toast', ['type' => 'success', 'message' => 'Cobertura terminada.']);
    }
}
