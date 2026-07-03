<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint JSON minimo (no Inertia) para la campana de notificaciones del
 * layout: no debe navegar ni reemplazar las props de la pagina actual, asi
 * que sigue el mismo patron ya usado en resources/js/lib/http.ts para la
 * previsualizacion de asignaciones (Fase 2).
 */
class NotificacionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $usuario = $request->user();

        return response()->json([
            'no_leidas' => $usuario->unreadNotifications()->count(),
            'recientes' => $usuario->notifications()->latest()->limit(10)->get()->map(fn ($notificacion) => [
                'id' => $notificacion->id,
                'tipo' => $notificacion->data['tipo'] ?? null,
                'titulo' => $notificacion->data['titulo'] ?? '',
                'mensaje' => $notificacion->data['mensaje'] ?? '',
                'url' => $notificacion->data['url'] ?? null,
                'leida' => $notificacion->read_at !== null,
                'creada_en' => $notificacion->created_at?->diffForHumans(),
            ]),
        ]);
    }

    public function marcarLeida(Request $request, string $notificacion): JsonResponse
    {
        $registro = $request->user()->notifications()->whereKey($notificacion)->firstOrFail();
        $registro->markAsRead();

        return response()->json(['estado' => 'ok']);
    }

    public function marcarTodasLeidas(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['estado' => 'ok']);
    }
}
