<?php

namespace App\Services\Reuniones;

use App\Enums\EstadoWebhook;
use App\Enums\ProveedorSesion;
use App\Jobs\ProcesarWebhookZoomJob;
use App\Models\WebhookRecibido;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;

/**
 * Verifica la firma HMAC de los webhooks de Zoom (`x-zm-signature`) y
 * resuelve el reto de validación de URL (`endpoint.url_validation`) que
 * Zoom exige antes de activar un endpoint. La idempotencia real la da el
 * índice único (proveedor, identificador_evento) de `webhooks_recibidos`:
 * un mismo evento reenviado por Zoom (reintentos ante timeout o 5xx del
 * lado del webhook) nunca se procesa dos veces. Ver
 * docs/AUDITORIA_CUMPLIMIENTO.md sección 2 y
 * App\Http\Controllers\Reuniones\ZoomWebhookController.
 */
class ZoomWebhookService
{
    /**
     * @return array{plainToken: string, encryptedToken: string}
     */
    public function responderValidacionUrl(string $plainToken): array
    {
        return [
            'plainToken' => $plainToken,
            'encryptedToken' => hash_hmac('sha256', $plainToken, (string) config('services.zoom.webhook_secret')),
        ];
    }

    public function firmaValida(Request $request): bool
    {
        $secreto = config('services.zoom.webhook_secret');
        $firma = (string) $request->header('x-zm-signature');
        $timestamp = (string) $request->header('x-zm-request-timestamp');

        if (! $secreto || $firma === '' || $timestamp === '') {
            return false;
        }

        $mensaje = "v0:{$timestamp}:{$request->getContent()}";
        $esperada = 'v0='.hash_hmac('sha256', $mensaje, $secreto);

        return hash_equals($esperada, $firma);
    }

    /**
     * Registra el webhook de forma idempotente y encola su procesamiento.
     * Devuelve null cuando el evento ya se había recibido antes (reintento
     * de Zoom), en cuyo caso no hay nada más que hacer que responder 200.
     *
     * @param  array<string, mixed>  $payload
     */
    public function registrar(array $payload, string $rawBody, bool $firmaValida): ?WebhookRecibido
    {
        $evento = $payload['event'] ?? 'desconocido';
        $objeto = $payload['payload']['object'] ?? [];

        $identificadorEvento = collect([
            $evento,
            $objeto['uuid'] ?? $objeto['id'] ?? null,
            $payload['event_ts'] ?? null,
        ])->filter()->implode(':');

        if ($identificadorEvento === '') {
            $identificadorEvento = hash('sha256', $rawBody);
        }

        try {
            $webhook = WebhookRecibido::create([
                'proveedor' => ProveedorSesion::Zoom,
                'identificador_evento' => $identificadorEvento,
                'tipo' => $evento,
                'hash_payload' => hash('sha256', $rawBody),
                'payload_normalizado' => $payload,
                'firma_valida' => $firmaValida,
                'estado' => $firmaValida ? EstadoWebhook::Recibido : EstadoWebhook::Descartado,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        if ($firmaValida) {
            ProcesarWebhookZoomJob::dispatch($webhook);
        }

        return $webhook;
    }
}
