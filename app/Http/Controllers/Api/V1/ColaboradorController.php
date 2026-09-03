<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Solicitudes\StoreSolicitudInternaRequest;
use App\Http\Resources\Api\V1\SolicitudInternaResource;
use App\Services\Colaboradores\ColaboradorPerfilService;
use App\Services\Colaboradores\NotificacionesService;
use App\Services\Solicitudes\SolicitudesService;
use App\Services\Vacaciones\VacacionesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Datos propios del colaborador para la app móvil. Toda la lógica vive en
 * ColaboradorPerfilService/SolicitudesService/NotificacionesService/
 * VacacionesService — los mismos servicios que usan las páginas Inertia —
 * este controlador solo autoriza (implícito: siempre "el usuario
 * autenticado"), valida y transforma a JSON.
 */
class ColaboradorController extends Controller
{
    public function __construct(
        private readonly ColaboradorPerfilService $perfil,
        private readonly SolicitudesService $solicitudes,
        private readonly NotificacionesService $notificaciones,
        private readonly VacacionesService $vacaciones,
    ) {}

    public function perfil(Request $request): JsonResponse
    {
        return response()->json($this->perfil->perfil($request->user()));
    }

    public function dashboard(Request $request): JsonResponse
    {
        return response()->json($this->perfil->dashboard($request->user()));
    }

    public function vacaciones(Request $request): JsonResponse
    {
        return response()->json($this->vacaciones->saldo($request->user()));
    }

    public function solicitudes(Request $request): JsonResponse
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

    public function storeSolicitud(StoreSolicitudInternaRequest $request): JsonResponse
    {
        $solicitud = $this->solicitudes->crear($request->user(), $request->validated());

        return response()->json(new SolicitudInternaResource($solicitud), 201);
    }

    public function notificaciones(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->notificaciones->listar($request->user()),
        ]);
    }
}
