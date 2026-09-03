<?php

namespace App\Http\Controllers;

use App\Services\Colaboradores\NotificacionesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint JSON minimo (no Inertia) para la campana de notificaciones del
 * layout: no debe navegar ni reemplazar las props de la pagina actual, asi
 * que sigue el mismo patron ya usado en resources/js/lib/http.ts para la
 * previsualizacion de asignaciones (Fase 2). Toda la logica vive en
 * NotificacionesService, compartida con Api\V1\NotificacionController.
 */
class NotificacionController extends Controller
{
    public function __construct(private readonly NotificacionesService $notificaciones) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->notificaciones->resumen($request->user()));
    }

    public function marcarLeida(Request $request, string $notificacion): JsonResponse
    {
        $this->notificaciones->marcarLeida($request->user(), $notificacion);

        return response()->json(['estado' => 'ok']);
    }

    public function marcarTodasLeidas(Request $request): JsonResponse
    {
        $this->notificaciones->marcarTodasLeidas($request->user());

        return response()->json(['estado' => 'ok']);
    }
}
