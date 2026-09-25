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

    /**
     * Al tocar una notificación en la app: la marca leída y devuelve el
     * estado ACTUAL del recurso (`atendida`, `estado_recurso`,
     * `mensaje_estado`) para avisar "ya fue atendida" si alguien la resolvió
     * desde la web mientras tanto. La navegación sigue usando
     * `data.type`/`data.resource_id` del listado.
     */
    public function abrir(Request $request, string $notificacion): JsonResponse
    {
        return response()->json(['data' => $this->notificaciones->abrir($request->user(), $notificacion)]);
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
