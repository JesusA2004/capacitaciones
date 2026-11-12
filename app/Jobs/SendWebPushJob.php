<?php

namespace App\Jobs;

use App\Models\WebPushSuscripcion;
use App\Services\Notificaciones\MonitorNotificacionesService;
use App\Services\WebPush\WebPushNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Envía un Web Push a UN navegador, en la misma cola dedicada de avisos que
 * App\Jobs\SendExpoPushJob (se encola después del commit: un fallo aquí
 * nunca deshace la acción principal).
 *
 * - Suscripción vencida (404/410: el usuario quitó el permiso o borró los
 *   datos del sitio): se elimina y no se reintenta.
 * - Servicio push caído / límite (429, 5xx): reintento con espera.
 * - Cualquier otro rechazo (400/401/403: llaves VAPID que ya no coinciden):
 *   se registra en el log y en el diagnóstico, y no se reintenta.
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

    public function handle(WebPushNotifier $web, MonitorNotificacionesService $monitor): void
    {
        $suscripcion = WebPushSuscripcion::query()->whereKey($this->suscripcionId)->first();

        if ($suscripcion === null) {
            return;
        }

        if (! $web->habilitado()) {
            // Web Push apagado o sin llaves VAPID: se deja rastro en vez de
            // descartar el aviso en silencio.
            Log::warning('Web push no enviado: Web Push apagado o sin llaves VAPID en el servidor.', ['suscripcion_id' => $suscripcion->id]);

            return;
        }

        $reporte = $web->enviarAhora($suscripcion, $this->payload);

        if ($reporte['exito']) {
            $suscripcion->forceFill(['ultimo_envio_en' => now()])->save();
            $monitor->registrar(MonitorNotificacionesService::ULTIMO_PUSH_WEB);

            return;
        }

        if ($reporte['vencida']) {
            $suscripcion->delete();

            return;
        }

        $estado = $reporte['estado'];

        if ($estado === null || $estado === 429 || $estado >= 500) {
            // Sin respuesta (p. ej. cURL sin certificados de CA) o servicio
            // saturado: se deja rastro del motivo antes de reintentar.
            Log::warning('Web push no entregado, se reintentará', [
                'suscripcion_id' => $suscripcion->id,
                'estado' => $estado,
                'intento' => $this->attempts(),
                'motivo' => $reporte['motivo'],
            ]);

            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff * $this->attempts());

                return;
            }

            throw new RuntimeException('Web push: el servicio push no respondió tras todos los reintentos.');
        }

        // 400/401/403: casi siempre llaves VAPID distintas a las de la
        // suscripción (se cambiaron en el servidor) o VAPID_SUBJECT inválido.
        Log::warning('Web push rechazado', [
            'suscripcion_id' => $suscripcion->id,
            'estado' => $estado,
            'motivo' => $reporte['motivo'],
        ]);

        $monitor->registrar(MonitorNotificacionesService::ULTIMO_FALLO_PUSH_WEB);
    }

    /**
     * Falla definitiva: el aviso ya está en la campana; solo se perdió la
     * notificación del navegador. Queda en failed_jobs y en el diagnóstico.
     */
    public function failed(?Throwable $exception): void
    {
        app(MonitorNotificacionesService::class)->registrar(MonitorNotificacionesService::ULTIMO_FALLO_PUSH_WEB);
    }
}
