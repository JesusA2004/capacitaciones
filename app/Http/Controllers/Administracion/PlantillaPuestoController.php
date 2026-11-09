<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administracion\ActualizarPlantillaPuestoRequest;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\Headcount\PlantillaAutorizadaService;
use Illuminate\Http\RedirectResponse;

/**
 * «Plantilla autorizada» desde la ficha del puesto (Puestos y salarios):
 * RH autoriza N plazas de CUALQUIER puesto en cualquier ubicación,
 * Corporativo incluido. La regla, la bitácora y la sincronización de
 * vacantes viven en PlantillaAutorizadaService (la misma del detalle de
 * sucursal).
 */
class PlantillaPuestoController extends Controller
{
    public function __construct(private readonly PlantillaAutorizadaService $plantilla) {}

    public function update(ActualizarPlantillaPuestoRequest $request, Puesto $puesto): RedirectResponse
    {
        $sucursal = Sucursal::query()->whereKey($request->integer('sucursal_id'))->firstOrFail();
        $plazas = $request->integer('plantilla_autorizada');

        $this->plantilla->actualizar($sucursal, $puesto, $plazas, $request->string('motivo')->toString(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => sprintf(
            '%s en %s: %d %s autorizada%s. Las vacantes se ajustaron solas.',
            $puesto->nombre,
            $sucursal->nombre,
            $plazas,
            $plazas === 1 ? 'plaza' : 'plazas',
            $plazas === 1 ? '' : 's',
        )]);
    }
}
