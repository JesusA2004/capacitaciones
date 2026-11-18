<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\CambioSucursalRequest;
use App\Services\MovimientosLaborales\CambioSucursalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * «Cambio de sucursal» desde Puestos y salarios (Trasladar personas →
 * Sucursal / Ambos) y desde el expediente. Delgado: la regla vive en
 * CambioSucursalService.
 */
class CambioSucursalController extends Controller
{
    public function __construct(private readonly CambioSucursalService $cambios) {}

    /** Opciones (sucursales con sus plazas) y, con sucursal, el resumen antes de confirmar. */
    public function vistaPrevia(CambioSucursalRequest $request): JsonResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 401);

        $colaboradores = $this->cambios->colaboradores($request->colaboradorIds(), $actor);
        $datos = $request->datos();
        $puesto = $this->cambios->puestoDestino($colaboradores, $datos['puesto_destino_id']);

        return response()->json([
            'puesto' => ['id' => $puesto->id, 'nombre' => $puesto->nombre],
            'sucursales' => $this->cambios->opciones($colaboradores, $puesto),
            'resumen' => $datos['sucursal_id'] > 0 ? $this->cambios->vistaPrevia($colaboradores, $datos, $actor) : null,
        ]);
    }

    public function store(CambioSucursalRequest $request): RedirectResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 401);

        $colaboradores = $this->cambios->colaboradores($request->colaboradorIds(), $actor);
        $resultado = $this->cambios->aplicar($colaboradores, $request->datos(), $actor);
        $total = count($resultado['movidos']);
        $unico = $total === 1 ? $resultado['movidos'][0]['nombre'] : null;

        Inertia::flash('cambioSucursal', $resultado);

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf(
                '%s a %s%s.',
                $unico !== null ? sprintf('%s cambió', $unico) : sprintf('%d personas cambiaron', $total),
                $resultado['sucursal'],
                $resultado['plazas_autorizadas'] > 0 ? sprintf(' · %d plaza(s) autorizada(s)', $resultado['plazas_autorizadas']) : '',
            ),
        ]);
    }
}
