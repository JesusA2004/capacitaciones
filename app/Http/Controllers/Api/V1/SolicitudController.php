<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Solicitudes\StoreSolicitudInternaRequest;
use App\Http\Resources\Api\V1\SolicitudInternaResource;
use App\Models\SolicitudInterna;
use App\Services\Solicitudes\SolicitudesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Solicitudes internas propias del colaborador autenticado. Un colaborador
 * jamás ve solicitudes de otros a través de esta API (Fase 1 — la revisión
 * de RH/gerencia queda solo en la web, ver docs/API_MOVIL.md).
 */
class SolicitudController extends Controller
{
    public function __construct(private readonly SolicitudesService $solicitudes) {}

    public function index(Request $request): JsonResponse
    {
        $solicitudes = $this->solicitudes->paraColaborador($request->user());

        return response()->json([
            'data' => SolicitudInternaResource::collection($solicitudes->items()),
            'meta' => [
                'current_page' => $solicitudes->currentPage(),
                'last_page' => $solicitudes->lastPage(),
                'total' => $solicitudes->total(),
            ],
        ]);
    }

    public function store(StoreSolicitudInternaRequest $request): JsonResponse
    {
        $solicitud = $this->solicitudes->crear($request->user(), $request->validated());

        return response()->json(new SolicitudInternaResource($solicitud), 201);
    }

    public function show(Request $request, SolicitudInterna $solicitud): JsonResponse
    {
        $this->authorize('view', $solicitud);

        $solicitud->load(['documentos', 'historial']);

        return response()->json(new SolicitudInternaResource($solicitud));
    }
}
