<?php

namespace App\Http\Controllers;

use App\Services\Notificaciones\NotificationFeedService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Endpoint JSON minimo (no Inertia) para la campana de notificaciones del
 * layout: no debe navegar ni reemplazar las props de la pagina actual, asi
 * que sigue el mismo patron ya usado en resources/js/lib/http.ts para la
 * previsualizacion de asignaciones (Fase 2). Toda la logica vive en
 * NotificationFeedService (proyeccion unica de notifications + avisos +
 * celebraciones), compartida con Api\V1\NotificacionController — misma
 * fuente para campana, Centro de Notificaciones web y app.
 */
class NotificacionController extends Controller
{
    public function __construct(private readonly NotificationFeedService $feed) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->feed->resumen($request->user()));
    }

    public function marcarLeida(Request $request, string $notificacion): JsonResponse
    {
        $this->feed->marcarLeida($request->user(), $notificacion);

        return response()->json(['estado' => 'ok']);
    }

    /**
     * Clic en una notificación: la marca leída y devuelve a dónde navegar
     * y si lo que avisaba ya fue atendido.
     */
    public function abrir(Request $request, string $notificacion): JsonResponse
    {
        return response()->json($this->feed->abrir($request->user(), $notificacion));
    }

    /**
     * Clic en la notificación del sistema (Web Push, public/sw.js): mismo
     * efecto que el clic en la campana, pero como navegación normal.
     */
    public function ir(Request $request, string $notificacion): RedirectResponse
    {
        try {
            $destino = $this->feed->abrir($request->user(), $notificacion);
        } catch (ModelNotFoundException|ValidationException) {
            return $this->centro($request);
        }

        return is_string($destino['url'] ?? null) && $destino['url'] !== ''
            ? redirect()->to($destino['url'])
            : $this->centro($request);
    }

    /**
     * Destino de un aviso sin pantalla propia: «Mis notificaciones» para quien
     * tiene Mi espacio; el tablero (con la campana) para cuentas solo operativas.
     */
    public function centro(Request $request): RedirectResponse
    {
        return Gate::forUser($request->user())->allows('modo-colaborador')
            ? redirect()->route('portal.notificaciones')
            : redirect()->route('dashboard');
    }

    public function marcarTodasLeidas(Request $request): JsonResponse
    {
        $this->feed->marcarTodas($request->user());

        return response()->json(['estado' => 'ok']);
    }
}
