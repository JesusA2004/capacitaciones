<?php

namespace App\Jobs;

use App\Models\WebPushSuscripcion;
use App\Services\Notificaciones\MonitorNotificacionesService;
use App\Services\WebPush\ClavesVapidService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use RuntimeException;

/**
 * Envía un Web Push a UN navegador, en la misma cola dedicada de avisos que
 * App\Jobs\SendExpoPushJob (se encola después del commit: un fallo aquí
 * nunca deshace la acción principal).
 *
 * - Suscripción vencida (404/410: el usuario quitó el permiso o borró los
 *   datos del sitio): se elimina y no se reintenta.
 * - Servicio push caído / límite (429, 5xx): reintento con espera.
 * - Cualquier otro rechazo: se registra en el log y no se reintenta.
 */
class SendWebPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 30;

    /**
     * @param  array<string, mixed>  $payload  Ver App\Services\WebPush\WebPushNotifier::payload().
     */
    public function __construct(
        public readonly int $suscripcionId,
        public readonly array $payload,
    ) {
        $this->onQueue((string) config('queue.cola_notificaciones', 'notifications'));
    }

    public function handle(ClavesVapidService $claves, MonitorNotificacionesService $monitor): void
    {
        $suscripcion = WebPushSuscripcion::query()->whereKey($this->suscripcionId)->first();
        $vapid = $claves->vapid();

        if ($suscripcion === null || $vapid === null) {
            return;
        }

        $webPush = new WebPush(['VAPID' => $vapid], [
            'TTL' => (int) config('webpush.ttl'),
            'urgency' => 'high',
        ]);

        $reporte = $webPush->sendOneNotification(
            Subscription::create([
                'endpoint' => $suscripcion->endpoint,
                'publicKey' => $suscripcion->clave_publica,
                'authToken' => $suscripcion->token_autenticacion,
                'contentEncoding' => $suscripcion->codificacion,
            ]),
            (string) json_encode($this->payload),
        );

        if ($reporte->isSuccess()) {
            $suscripcion->forceFill(['ultimo_envio_en' => now()])->save();
            $monitor->registrar(MonitorNotificacionesService::ULTIMO_PUSH);

            return;
        }

        if ($reporte->isSubscriptionExpired()) {
            $suscripcion->delete();

            return;
        }

        $estado = $reporte->getResponse()?->getStatusCode();

        if ($estado === null || $estado === 429 || $estado >= 500) {
            // Sin respuesta (p. ej. cURL sin certificados de CA) o servicio
            // saturado: se deja rastro del motivo antes de reintentar.
            Log::warning('Web push no entregado, se reintentará', [
                'suscripcion_id' => $suscripcion->id,
                'estado' => $estado,
                'intento' => $this->attempts(),
                'motivo' => $reporte->getReason(),
            ]);

            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff * $this->attempts());

                return;
            }

            throw new RuntimeException('Web push: el servicio push no respondió tras todos los reintentos.');
        }

        Log::warning('Web push rechazado', [
            'suscripcion_id' => $suscripcion->id,
            'estado' => $estado,
            'motivo' => $reporte->getReason(),
        ]);
    }
}
