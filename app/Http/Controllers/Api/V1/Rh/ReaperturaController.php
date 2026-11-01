<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Enums\TipoReapertura;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reaperturas\ReabrirDecisionRequest;
use App\Services\Reaperturas\ReaperturaService;
use Illuminate\Http\JsonResponse;

class ReaperturaController extends Controller
{
    public function __construct(private readonly ReaperturaService $reaperturas) {}

    public function store(ReabrirDecisionRequest $request, string $tipo, int $id): JsonResponse
    {
        $reapertura = $this->reaperturas->reabrir(TipoReapertura::from($tipo), $id, $request->user(), (string) $request->validated('motivo'));

        return response()->json(['message' => 'Se reabrió la decisión y quedó en el historial.', 'data' => ['id' => $reapertura->id, 'tipo' => $reapertura->tipo->value]], 201);
    }
}
