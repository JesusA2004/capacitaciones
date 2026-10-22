<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AcuseDescriptivoPuesto;
use App\Services\Onboarding\AcuseDescriptivoPuestoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mi descriptivo de puesto (API v1, colaborador): el que RH le envió tras
 * aprobar la inducción, marcarlo leído y dar su «OK, enterado» con
 * comentario. Siempre el propio; la regla y las validaciones viven en
 * AcuseDescriptivoPuestoService.
 */
class DescriptivoPuestoColaboradorController extends Controller
{
    public function __construct(private readonly AcuseDescriptivoPuestoService $acuses) {}

    public function show(Request $request): JsonResponse
    {
        $acuse = $this->acuses->vigenteDe($request->user());

        return response()->json(['data' => $acuse !== null ? $this->acuses->aArray($acuse, true) : null]);
    }

    public function leido(Request $request, AcuseDescriptivoPuesto $acuse): JsonResponse
    {
        $acuse = $this->acuses->marcarLeido($acuse, $request->user());

        return response()->json(['data' => $this->acuses->aArray($acuse, true)]);
    }

    public function acusar(Request $request, AcuseDescriptivoPuesto $acuse): JsonResponse
    {
        $comentario = $request->input('comentario');
        // `conformidad` («Estoy de acuerdo con…») es opcional/aditivo: las
        // apps instaladas solo mandan `comentario`.
        $conformidad = $request->has('conformidad') ? (is_string($request->input('conformidad')) ? $request->input('conformidad') : '') : null;

        $acuse = $this->acuses->acusar($acuse, $request->user(), $request->boolean('enterado'), is_string($comentario) ? $comentario : '', $conformidad);

        return response()->json(['data' => $this->acuses->aArray($acuse, true)]);
    }
}
