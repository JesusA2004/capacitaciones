<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Services\Onboarding\AcuseDescriptivoPuestoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Descriptivo de puesto enviado tras la inducción (API v1, RH). Mismo
 * AcuseDescriptivoPuestoService que la web.
 */
class AcuseDescriptivoPuestoController extends Controller
{
    public function __construct(private readonly AcuseDescriptivoPuestoService $acuses) {}

    public function show(Request $request, Colaborador $colaborador): JsonResponse
    {
        abort_unless($request->user()->can('onboarding.induccion_puesto'), 403);
        $this->acuses->autorizarGestion($request->user(), $colaborador);

        return response()->json(['data' => $this->acuses->resumenRh($colaborador, $request->user())]);
    }

    public function enviar(Request $request, Colaborador $colaborador): JsonResponse
    {
        abort_unless($request->user()->can('onboarding.induccion_puesto'), 403);
        $this->acuses->enviar($colaborador, $request->user());

        return response()->json(['data' => $this->acuses->resumenRh($colaborador, $request->user())]);
    }
}
