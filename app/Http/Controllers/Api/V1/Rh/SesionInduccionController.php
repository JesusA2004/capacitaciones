<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Http\Controllers\Controller;
use App\Models\SesionInduccion;
use App\Services\Onboarding\SesionInduccionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sesiones de presentación de la Inducción Mr. Lana (API v1, RH). Mismo
 * SesionInduccionService que la web.
 */
class SesionInduccionController extends Controller
{
    public function __construct(private readonly SesionInduccionService $sesiones) {}

    public function index(Request $request): JsonResponse
    {
        $datos = $this->sesiones->datosPantalla($request->user());
        abort_if($datos === null, 403, 'No tienes permiso para presentar la inducción.');

        return response()->json(['data' => $datos]);
    }

    public function colaboradores(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->sesiones->buscarColaboradores($request->user(), $request->string('q')->toString())]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'colaborador_ids' => ['required', 'array', 'min:1', 'max:60'],
            'colaborador_ids.*' => ['required', 'integer'],
            'notas' => ['nullable', 'string', 'max:1000'],
        ], [
            'colaborador_ids.required' => 'Elige al menos a un colaborador al que le presentarás la inducción.',
            'colaborador_ids.min' => 'Elige al menos a un colaborador al que le presentarás la inducción.',
        ]);

        $sesion = $this->sesiones->iniciar($request->user(), $datos['colaborador_ids'], $datos['notas'] ?? null);

        return response()->json(['data' => $this->sesiones->aArray($sesion)], 201);
    }

    public function enviar(Request $request, SesionInduccion $sesion): JsonResponse
    {
        $sesion = $this->sesiones->enviarEvaluacion($sesion, $request->user());

        return response()->json(['data' => $this->sesiones->aArray($sesion)]);
    }

    public function cancelar(Request $request, SesionInduccion $sesion): JsonResponse
    {
        $sesion = $this->sesiones->cancelar($sesion, $request->user());

        return response()->json(['data' => $this->sesiones->aArray($sesion)]);
    }
}
