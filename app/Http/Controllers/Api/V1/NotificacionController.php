<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Notificaciones\NotificationFeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Centro de notificaciones único de la app: notifications + avisos +
 * celebraciones en una sola lista (NotificationFeedService) — nunca un
 * módulo "Avisos" separado para el usuario final.
 */
class NotificacionController extends Controller
{
    public function __construct(private readonly NotificationFeedService $feed) {}

    public function index(Request $request): JsonResponse
    {
        $porPagina = min(50, max(1, $request->integer('per_page', 30)));
        $pagina = max(1, $request->integer('page', 1));

        $paginador = $this->feed->feedPaginado($request->user(), $porPagina, $pagina);

        return response()->json([
            'data' => $paginador->items(),
            'meta' => [
                'current_page' => $paginador->currentPage(),
                'last_page' => $paginador->lastPage(),
                'per_page' => $paginador->perPage(),
                'total' => $paginador->total(),
            ],
        ]);
    }

    public function marcarLeida(Request $request, string $notificacion): JsonResponse
    {
        $this->feed->marcarLeida($request->user(), $notificacion);

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
        return response()->json(['data' => $this->feed->abrir($request->user(), $notificacion)]);
    }

    public function marcarTodasLeidas(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $actualizadas = $this->feed->contarNoLeidas($usuario);

        $this->feed->marcarTodas($usuario);

        return response()->json([
            'message' => 'Notificaciones marcadas como leídas',
            'updated' => $actualizadas,
        ]);
    }
}
