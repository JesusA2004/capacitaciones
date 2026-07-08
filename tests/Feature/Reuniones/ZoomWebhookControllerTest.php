<?php

use App\Enums\EstadoWebhook;
use App\Jobs\ProcesarWebhookZoomJob;
use App\Models\WebhookRecibido;
use Illuminate\Support\Facades\Queue;

/**
 * No hay credenciales reales de Zoom en este entorno: estas pruebas cubren
 * el contrato del endpoint (reto de validación de URL, verificación de
 * firma HMAC, idempotencia ante reintentos) que sí puede verificarse sin
 * red. La sincronización real de asistencia la cubre
 * ProcesarWebhookZoomJobTest y ZoomAsistenciaSincronizador vía Http::fake().
 */
beforeEach(function () {
    config(['services.zoom.webhook_secret' => 'secreto-de-prueba']);
});

function firmarWebhookZoom(array $payload, string $secreto = 'secreto-de-prueba'): array
{
    $raw = json_encode($payload);
    $timestamp = (string) time();

    return [
        'x-zm-signature' => 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$raw}", $secreto),
        'x-zm-request-timestamp' => $timestamp,
    ];
}

test('responde el reto de validacion de url de zoom sin exigir firma', function () {
    $respuesta = $this->postJson(route('webhooks.zoom'), [
        'event' => 'endpoint.url_validation',
        'payload' => ['plainToken' => 'token-de-reto'],
    ]);

    $respuesta->assertOk()->assertExactJson([
        'plainToken' => 'token-de-reto',
        'encryptedToken' => hash_hmac('sha256', 'token-de-reto', 'secreto-de-prueba'),
    ]);

    expect(WebhookRecibido::count())->toBe(0);
});

test('rechaza el reto de validacion de url si el webhook no tiene secreto configurado', function () {
    config(['services.zoom.webhook_secret' => null]);

    $respuesta = $this->postJson(route('webhooks.zoom'), [
        'event' => 'endpoint.url_validation',
        'payload' => ['plainToken' => 'token-de-reto'],
    ]);

    $respuesta->assertStatus(503);
});

test('rechaza un webhook con firma invalida y lo deja registrado como descartado', function () {
    $payload = [
        'event' => 'meeting.ended',
        'event_ts' => 1717000000000,
        'payload' => ['object' => ['id' => 123456789, 'uuid' => 'uuid-1']],
    ];

    $respuesta = $this->postJson(route('webhooks.zoom'), $payload, [
        'x-zm-signature' => 'v0=firma-que-no-coincide',
        'x-zm-request-timestamp' => (string) time(),
    ]);

    $respuesta->assertStatus(401);

    expect(WebhookRecibido::count())->toBe(1);
    $webhook = WebhookRecibido::first();
    expect($webhook->firma_valida)->toBeFalse();
    expect($webhook->estado)->toBe(EstadoWebhook::Descartado);
});

test('acepta un webhook con firma valida, lo registra una sola vez ante reintentos de zoom y encola su procesamiento', function () {
    Queue::fake();

    $payload = [
        'event' => 'meeting.ended',
        'event_ts' => 1717000000000,
        'payload' => ['object' => ['id' => 123456789, 'uuid' => 'uuid-1']],
    ];

    $respuesta1 = $this->postJson(route('webhooks.zoom'), $payload, firmarWebhookZoom($payload));
    $respuesta1->assertOk();

    // Zoom reintenta la misma entrega (mismo event_ts en el cuerpo) ante un
    // timeout o una respuesta 5xx anterior; debe reconocerse sin duplicar.
    $respuesta2 = $this->postJson(route('webhooks.zoom'), $payload, firmarWebhookZoom($payload));
    $respuesta2->assertOk();

    expect(WebhookRecibido::count())->toBe(1);

    $webhook = WebhookRecibido::first();
    expect($webhook->firma_valida)->toBeTrue();
    expect($webhook->estado)->toBe(EstadoWebhook::Recibido);

    Queue::assertPushed(ProcesarWebhookZoomJob::class, 1);
});

test('dos eventos distintos de la misma reunion se registran por separado', function () {
    Queue::fake();

    $terminada = [
        'event' => 'meeting.ended',
        'event_ts' => 1717000000000,
        'payload' => ['object' => ['id' => 123456789, 'uuid' => 'uuid-1']],
    ];

    $participante = [
        'event' => 'meeting.participant_joined',
        'event_ts' => 1717000005000,
        'payload' => ['object' => ['id' => 123456789, 'uuid' => 'uuid-1']],
    ];

    $this->postJson(route('webhooks.zoom'), $terminada, firmarWebhookZoom($terminada))->assertOk();
    $this->postJson(route('webhooks.zoom'), $participante, firmarWebhookZoom($participante))->assertOk();

    expect(WebhookRecibido::count())->toBe(2);
    Queue::assertPushed(ProcesarWebhookZoomJob::class, 2);
});
