<?php

namespace App\Http\Controllers;

use App\Services\WebPush\SuscripcionWebPushService;
use App\Services\WebPush\WebPushNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Activación de las notificaciones de escritorio (Web Push) del navegador
 * actual. JSON mínimo, como la campana (NotificacionController): no navega.
 * La suscripción la crea el navegador (PushManager.subscribe en
 * resources/js/composables/useWebPush.ts) y aquí solo se guarda/borra.
 */
class WebPushController extends Controller
{
    public function __construct(
        private readonly SuscripcionWebPushService $suscripciones,
        private readonly WebPushNotifier $web,
    ) {}

    public function suscribir(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'endpoint' => ['required', 'string', 'url:https,http', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'string', 'in:aes128gcm,aesgcm'],
        ], [
            'endpoint.required' => 'El navegador no entregó una suscripción válida.',
            'keys.p256dh.required' => 'El navegador no entregó una suscripción válida.',
            'keys.auth.required' => 'El navegador no entregó una suscripción válida.',
        ]);

        $this->suscripciones->registrar($request->user(), $datos, $request->userAgent());

        return response()->json(['estado' => 'ok']);
    }

    public function desuscribir(Request $request): JsonResponse
    {
        $datos = $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);

        $this->suscripciones->eliminar($request->user(), $datos['endpoint']);

        return response()->json(['estado' => 'ok']);
    }

    /**
     * «Probar»: confirma al usuario que su navegador ya recibe avisos.
     */
    public function prueba(Request $request): JsonResponse
    {
        $enviados = $this->web->aUsuario(
            $request->user(),
            '🔔 Notificaciones activadas',
            'Así te avisaremos de solicitudes, aprobaciones y novedades de MR. LANA PEOPLE, aunque no tengas la página abierta.',
            ['type' => 'push_test'],
        );

        return response()->json(['estado' => 'ok', 'navegadores' => $enviados]);
    }
}
