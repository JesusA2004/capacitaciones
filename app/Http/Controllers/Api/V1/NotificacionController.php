<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Colaboradores\NotificacionesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificacionController extends Controller
{
    public function __construct(private readonly NotificacionesService $notificaciones) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->notificaciones->listar($request->user())]);
    }

    public function marcarLeida(Request $request, string $notificacion): JsonResponse
    {
        $this->notificaciones->marcarLeida($request->user(), $notificacion);

        return response()->json(['estado' => 'ok']);
    }

    public function marcarTodasLeidas(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $actualizadas = $usuario->unreadNotifications()->count();

        $this->notificaciones->marcarTodasLeidas($usuario);

        return response()->json([
            'message' => 'Notificaciones marcadas como leídas',
            'updated' => $actualizadas,
        ]);
    }
}
