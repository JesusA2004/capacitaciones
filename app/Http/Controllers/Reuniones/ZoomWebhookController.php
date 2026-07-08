<?php

namespace App\Http\Controllers\Reuniones;

use App\Http\Controllers\Controller;
use App\Services\Reuniones\ZoomWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint público (sin sesión de Laravel ni CSRF, ver bootstrap/app.php)
 * que recibe los webhooks de Zoom. Zoom exige responder al reto
 * `endpoint.url_validation` sin firma antes de activar el endpoint; el
 * resto de eventos sí llega firmado (`x-zm-signature`) y se rechaza si la
 * firma no coincide. El procesamiento real (asociar la sesión, disparar la
 * sincronización de asistencia) se delega a un Job encolado para responder
 * a Zoom en milisegundos, como exige su documentación.
 */
class ZoomWebhookController extends Controller
{
    public function __construct(private readonly ZoomWebhookService $service) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->json()->all();

        if (($payload['event'] ?? null) === 'endpoint.url_validation') {
            $plainToken = $payload['payload']['plainToken'] ?? '';

            if ($plainToken === '' || ! config('services.zoom.webhook_secret')) {
                return response()->json(['message' => 'Webhook de Zoom no configurado.'], 503);
            }

            return response()->json($this->service->responderValidacionUrl($plainToken));
        }

        $firmaValida = $this->service->firmaValida($request);

        if (! $firmaValida) {
            $this->service->registrar($payload, $request->getContent(), false);

            return response()->json(['message' => 'Firma inválida.'], 401);
        }

        $this->service->registrar($payload, $request->getContent(), true);

        return response()->json(['message' => 'Recibido.']);
    }
}
