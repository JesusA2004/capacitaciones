<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vacaciones\StoreSolicitudVacacionesRequest;
use App\Http\Resources\Api\V1\SolicitudVacacionesResource;
use App\Services\Vacaciones\VacacionesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VacacionesController extends Controller
{
    public function __construct(private readonly VacacionesService $vacaciones) {}

    public function saldo(Request $request): JsonResponse
    {
        return response()->json($this->vacaciones->saldo($request->user()));
    }

    public function solicitudes(Request $request): JsonResponse
    {
        return response()->json([
            'data' => SolicitudVacacionesResource::collection($this->vacaciones->misSolicitudes($request->user())),
        ]);
    }

    public function storeSolicitud(StoreSolicitudVacacionesRequest $request): JsonResponse
    {
        try {
            $solicitud = $this->vacaciones->solicitar($request->user(), $request->validated());
        } catch (ValidationException $e) {
            return response()->json(['message' => 'No tienes suficientes días disponibles.', 'errors' => $e->errors()], 422);
        }

        return response()->json(new SolicitudVacacionesResource($solicitud), 201);
    }
}
